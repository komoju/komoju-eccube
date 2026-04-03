<?php

namespace Plugin\komoju;

require_once __DIR__ . '/Resource/komoju_lib/init.php';

use Komoju\KomojuApi;
use Komoju\Payments;

class KomojuClient{

    protected $secret_key;
    protected $api_obj;

    public static $error_msg = [
        "bad_request"   =>  "不正な要求です。サーバーは要求を処理できません。",
        "unauthorized"  =>  "ユーザーの認証に失敗しました。",
        "not_found" =>  "リソースが見つかりませんでした。",
        "internal_server_error" =>  "内部エラーが発生しました。",
        "forbidden" =>  "リソースへのアクセスは許可されていません。",
        "unprocessable_entity"  =>  "指定されたパラメータに何らかの不整合があるため処理できません。",
        "bad_gateway"   =>  "上位ゲートウェイがエラーを返却しました。",
        "gateway_timeout"   =>  "支払いの処理中にゲートウェイタイムアウトが発生しました。請求は行われていません。支払いを再試行してください。",
        "service_unavailable"   =>  "メンテナンス中です。しばらくしてからもう一度実行してください。",
        "request_failed"    =>  "リクエストが失敗しました。",
        "invalid_payment_type"  =>  "支払い方法が無効です。 %{provided} は %{allowed} の一つではありません。",
        "invalid_token" =>  "トークンの値が不正です。",
        "invalid_currency"  =>  "指定された通貨が不正です。",
        "not_refundable"    =>  "返金できない支払いです。",
        "not_capturable"    =>  "入金できない決済です。",
        "not_cancellable"   =>  "キャンセルできない支払いです。",
        "not_chargebackable"    =>  "translation missing: ja.errors.unprocessable_entity.not_chargebackable",
        "fraudulent"    =>  "不正行為の疑いのある支払いです。",
        "invalid_parameter" =>  "%{param} の値が不正です。",
        "missing_parameter" =>  "必須パラメータ (%{param}) が指定されていません。",
        "insufficient_funds"    =>  "残高不足です。",
        "used_number"   =>  "使用済みの番号です。",
        "card_declined" =>  "カードは拒否されました。",
        "invalid_password"  =>  "パスワードが不正です。",
        "bad_verification_value"    =>  "セキュリティーコードが不正です。",
        "exceeds_limit" =>  "決済可能額を超過しました。",
        "card_expired"  =>  "カードは期限切れです。",
        "invalid_number"    =>  "指定された番号が不正です。",
        "invalid_account"   =>  "アカウントが不正です。",
        "restricted_account"    =>  "ja.errors.bad_gateway.restricted_account",
        "other_error"   =>  "ja.errors.bad_gateway.other_error",
        "invalid_user_key"  =>  "ja.errors.bad_gateway.invalid_user_key",
        "other_invalid" =>  "Invalid card",
    ];

    public function __construct($secret_key){
        $this->secret_key = $secret_key;
    }
    public function getPayments($page = null, $per_page = null){
        $this->api_obj = new Payments($this->secret_key);
        return $this->api_obj->get();
    }
    public function getPayment($payment_id){
        $this->api_obj = new Payments($this->secret_key);
        return $this->api_obj->getOne($payment_id);
    }
    public function createPayment($data){
        $this->api_obj = new Payments($this->secret_key);
        return $this->api_obj->create($data);
    }

    public function refundPayment($id, $data){
        $this->api_obj = new Payments($this->secret_key);
        return $this->api_obj->refund($id, $data);
    }
    public function capturePayment($payment_id){
        $this->api_obj = new Payments($this->secret_key);
        return $this->api_obj->capture($payment_id);
    }
    public function cancelPayment($payment_id){
        $this->api_obj = new Payments($this->secret_key);
        return $this->api_obj->cancel($payment_id);
    }


    public function getStatusCode(){
        if($this->api_obj){
            return $this->api_obj->getStatusCode();
        }
        return null;
    }
    public function getLastError(){
        if($this->api_obj){
            $error_code = $this->api_obj->getLastError();
            if(isset(self::$error_msg[$error_code])){
                return self::$error_msg[$error_code];
            }
            return null;
        }
        return null;
    }
}