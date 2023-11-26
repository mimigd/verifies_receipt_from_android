<?php
namespace Library;

use Google\Exception;
use Google\Service\AndroidPublisher;
use Google_Service_AndroidPublisher;
use GuzzleHttp\Client;
use Models\WalletRecharge;
use Models\WalletRechargeLog;

require_once __DIR__ . '/../../vendor/google/apiclient-services/autoload.php';

class GooglePay
{
    const PURCHASE_STATE_PENDING     = 0;       // 付款中
    const CONSUMPTION_PURCHASED      = 1;       // consumptionState 已消費狀態
    const ACKNOWLEDGEMENT_NOR_YET    = 0;       // 尚未承認訂單狀態

    public $packageName              = '';      // 應用產品包的名稱,如:'com.some.thing'
    public $clientProductId          = '';      // 管理產品的字符串ID,通常在Google Developer Console創建,由client傳送過來
    public $clientToken              = '';      // 購買token由client傳送過來,一並傳送至google驗證發票

    /**
     * @var Google_Service_AndroidPublisher
     */
    protected $service               = '';      // 暫存httpClient
    protected $dirJson               = '';      // 密鑰json目錄

    protected $kind                  = '';      // androidpublisher中的其中一個方法
    protected $purchaseTimeMillis    = '';
    protected $purchaseState         = '';      // 訂單的購買狀態,0:購買,1:取消,2:Pending
    protected $consumptionState      = '';      // 產品的消費狀態,0:尚未消費,1:已消費 (因爲google的同一個商品未消費的話是不允許重複購買的)
    protected $developerPayload      = '';      // 指定字符,包含訂單的補充訊息,承認發票時可傳送給google,不能重複
    protected $orderId               = '';
    protected $purchaseType          = '';      // 產品的購買類型,0:測試帳戶購買,1:促銷(使用促銷代買購買),2:獎勵(可能透過觀看影片而不是付費)
    protected $acknowledgementState  = '';      // 產品確認狀態,0:尚未確認,1:已確認
    protected $purchaseToken         = '';      // 為辨識此次購買的token,不一定存在
    protected $productId             = '';      // 產品的SKU,不一定存在
    protected $quantity              = '';      // 購買產品的相關數量,如果不存在則數量為1

    protected $obfuscatedExternalAccountId = ''; // 使用BillingFlowParams才會出現的東西
    protected $obfuscatedExternalProfileId = ''; // 使用BillingFlowParams才會出現的東西

    public $test = '';

    public function __construct($clientProductId, $clientToken)
    {
        $config              = \Phalcon\Di::getDefault()->getShared("config");
        $this->dirJson       = $config['google_pay']['dirJson'];
        $this->packageName   = $config['google_pay']['packageName'];

        $this->clientProductId = $clientProductId;
        $this->clientToken     = $clientToken;

    }

    /**
     * 使用app回傳訊息向google取得收據
     * @return bool
     */
    public function getProducts(){
        putenv("GOOGLE_APPLICATION_CREDENTIALS=$this->dirJson");    // 設定json置系統變數,SDK會自動讀取
        $android = new AndroidPublisher();
        $android->getClient()->useApplicationDefaultCredentials();
        $android->getClient()->addScope('https://www.googleapis.com/auth/androidpublisher');
        $android->getClient()->setHttpClient(new Client(array('verify' => false)));
        $this->service = new Google_Service_AndroidPublisher($android->getClient());

        try {
            $purchase = $this->service->purchases_products->get($this->packageName, $this->productId, $this->clientToken);
            $this->kind                        = $purchase->kind;
            $this->purchaseTimeMillis          = $purchase->purchaseTimeMillis;
            $this->purchaseState               = $purchase->purchaseState;
            $this->consumptionState            = $purchase->consumptionState;
            $this->developerPayload            = $purchase->developerPayload;
            $this->orderId                     = $purchase->orderId;
            $this->purchaseType                = $purchase->purchaseType;
            $this->acknowledgementState        = $purchase->acknowledgementState;
            $this->purchaseToken               = $purchase->purchaseToken;
            $this->productId                   = $purchase->productId;
            $this->quantity                    = $purchase->quantity;
            $this->obfuscatedExternalProfileId = $purchase->obfuscatedExternalProfileId;
            $this->obfuscatedExternalAccountId = $purchase->obfuscatedExternalAccountId;
            return true;
        }catch (Exception $e){
            return false;
        }

    }

    /**
     * 向google承認這筆收據
     * @param $developerPayload string 額外記錄在收據的文字
     */
    public function acknowledge($developerPayload=''){
        $request = new AndroidPublisher\ProductPurchasesAcknowledgeRequest();
        $request->developerPayload = $developerPayload;
        $this->service->purchases_products->acknowledge($this->packageName, $this->clientProductId, $this->clientToken, $request);
    }

    /**
     * @return array
     */
    public function getInfo(){
        return [
            'kind'                           => $this->kind,
            'purchaseTimeMillis'             => $this->purchaseTimeMillis,
            'purchaseState'                  => $this->purchaseState,
            'consumptionState'               => $this->consumptionState,
            'developerPayload'               => $this->developerPayload,
            'orderId'                        => $this->orderId,
            'purchaseType'                   => $this->purchaseType,
            'acknowledgementState'           => $this->acknowledgementState,
            'purchaseToken'                  => $this->purchaseToken,
            'productId'                      => $this->productId,
            'quantity'                       => $this->quantity,
            'obfuscatedExternalAccountId'    => $this->obfuscatedExternalAccountId,
            'obfuscatedExternalProfileId'    => $this->obfuscatedExternalProfileId,
        ];
    }

    /**
     * 驗證發票有效性
     * @param $wallet_rechatge_id
     * @return bool
     */
    public function checkInfo($wallet_recharge_id){
        if((int)$this->purchaseState !== (int)GooglePay::PURCHASE_STATE_PENDING){
            return false;
        }

        if((int)$this->consumptionState !== (int)GooglePay::CONSUMPTION_PURCHASED){
            return false;
        }

        // 確認是否承認發票
        if((int)$this->acknowledgementState !== (int)GooglePay::ACKNOWLEDGEMENT_NOR_YET){
            return false;
        }

        // 確認是否同一方案
        $ProductId = WalletRecharge::findFirst($wallet_recharge_id);
        if($ProductId){
            $ProductId = $ProductId->toArray()['product_id'];
        }
        if((string)$this->clientProductId !== (string)$ProductId){
            return false;
        }

        if(!$this->checkTransactionID()){
            return false;
        }

        return true;
    }

    /**
     * 檢查orderID是否有被使用過
     * @return bool
     */
    public function checkTransactionID(){
        $walletRechargeLogList = WalletRechargeLog::findFirst([
            'receipt = :receipt:',
            'bind' => ['receipt' => $this->orderId]
        ]);
        return !$walletRechargeLogList;
    }
}
