# 驗證 Android 裝置的訂單

這些代碼用來驗證來自Android的訂單(收據)

申請json金鑰請參考[此教學](https://github.com/mimigd/verifies_receipt_from_android/blob/main/Requesting_Android_Payment_Key_Guide/Guide.md)

## 準備環境

將以下代碼添加到您的項目的 `composer.json` 文件中：

```json
{
    "require": {
        "google/apiclient-services": "v0.252.0"
    }
}
```

然後運行 `composer install` 以安裝該庫及其依賴項。

## 配置

- 替換 `WalletRecharge` 和 `WalletRechargeLog` 為你使用的 ORM 或資料表。
- 將 `$config` 替換為你的應用程序的配置。
- `packageName`：您的 Android 應用程序的包名（例如 'com.example.myapp'）。
- `dirJson`：Google Play API 的 JSON 金鑰文件的目錄路徑。

## 用法

### 初始化

```php
<?php

use Library\GooglePay;

// 使用您的客戶端產品 ID 和客戶端令牌初始化 GooglePay 對象
$clientProductId = 'your-client-product-id';
$clientToken = 'your-client-token';
$googlePay = new GooglePay($clientProductId, $clientToken);
```

### 檢索購買信息

您可以使用 `getProducts` 方法從 Google Play 檢索購買信息：

```php
if ($googlePay->getProducts()) {
    // 可用的購買信息
    $purchaseInfo = $googlePay->getInfo();
    
    // 根據需要檢查並處理購買
    if ($googlePay->checkInfo($walletRechargeId)) {
        // 處理購買
        $googlePay->acknowledge('可選的開發者載荷');
    } else {
        // 處理無效購買
    }
} else {
    // 無法檢索購買信息
}
```

### 檢查交易 ID

還可以使用 `checkTransactionID` 方法檢查訂單 ID 是否已使用：

```php
if (!$googlePay->checkTransactionID()) {
    // 訂單 ID 尚未使用過
} else {
    // 訂單 ID 已經使用過
}
```