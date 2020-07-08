<?php
/**
 * @author Kirill Silianov <kirill.silianov@gmail.com>
 * Date: 28.08.2017
 * Time: 9:45
 *
 * @version 0.2.3
 */

class OpenApiConnector
{
    /**
     * @var string BASE_URL Базовый адрес API сервиса.
     *
     * http://check-dev.business.ru/open-api/v1/ - Тестовый сервис.
     * https://check.business.ru/open-api/v1/ - Stable сервис.
     */
    const BASE_URL = "check-dev.business.ru/open-api/v1/";

    /**
     * @var string STATIC_APP_ID app_id сервиса (заменить своим из интеграции, если не используется внешнее хранение переменной).
     */
    const STATIC_APP_ID = "d78c7aa5-9edb-4d99-8159-a5655c3a4d1f";

    /**
     * @var string STATIC_SECRET_KEY Secret key сервиса (заменить своим из интеграции, если не используется внешнее хранение переменной).
     */
    const STATIC_SECRET_KEY = "fMBRGdACY6LyqDKuk24wS7pv1cIgjE8n";

    /**
     * @var string GET_TOKEN_URL Адрес для получения токена.
     */
    const GET_TOKEN_URL = "Token";

    /**
     * @var string GET_SYSTEM_STATUS Адрес для получения состояния системы.
     */
    const GET_SYSTEM_STATUS = "StateSystem";

    /**
     * @var string COMMAND_URL Адррес для передачи комманды на ККТ.
     */
    const COMMAND_URL = "Command";

    /**
     * @var false|string $appID App_id интеграции.
     */
    private $appID = false;

    /**
     * @var false|string $token Токен интеграции.
     */
    private $token = false;

    /**
     * @var false|string $secret Secret key интеграции.
     */
    private $secret = false;

    /**
     * @var false|string $nonce "Соль" команды (Является уникальным идентификатором команды).
     */
    private $nonce = false;

    /**
     * OpenApiConnector constructor.
     *
     * Задает app_id и secret_key интеграции из параметров или из констант при отсутствии.
     * Генерирует новый идентификатор команды.
     * Получает новый токен.
     *
     * @param string $appID
     * @param string $secret
     */
    public function __construct($appID = self::STATIC_APP_ID, $secret = self::STATIC_SECRET_KEY)
    {
        $this->appID = $appID;
        $this->secret = $secret;
        $this->getNonce();
        $this->getToken();
    }

    /**
     * Метод возвращает app_id интеграции.
     *
     * @return string app_id интеграции.
     */
    public function getAppID()
    {
        return $this->appID;
    }

    /**
     * Метод возвращает secret_key интеграции.
     *
     * @return string secret_key интеграции.
     */
    public function getSecretKey()
    {
        return $this->secret;
    }

    /**
     * Метод отправляет запрос на генерацию нового токена и записывает его в переменную объекта.
     */
    public function getToken() //TODO: rename
    {
        $token = json_decode(
            $this->sendRequest(
                self::GET_TOKEN_URL,
                [
                    "nonce" => $this->nonce,
                    "app_id" => $this->appID
                ]
            ),
            true
        )["token"];
        $this->token = $token;
    }

    /**
     * Метод вотправляет звапрос на открытие смены на ККТ.
     *
     * @return false|string Ошибка выполнения запроса.|Строка JSON ответа на запрос.
     */
    public function openShift()
    {
        return $this->sendRequest(
            self::COMMAND_URL,
            [
                "nonce" => $this->nonce,
                "app_id" => $this->appID,
                "token" => $this->token,
                "type" => "openShift",
                "command" => [
                    "report_type" => false,
                    "author" => "name" //TODO: make name
                ]
            ]
        );
    }

    /**
     * Метод вотправляет звапрос на закрытие смены на ККТ.
     *
     * @return false|string Ошибка выполнения запроса.|Строка JSON ответа на запрос.
     */
    public function closeShift()
    {
        return $this->sendRequest(
            self::COMMAND_URL,
            [
                "nonce" => $this->nonce,
                "app_id" => $this->appID,
                "token" => $this->token,
                "type" => "closeShift",
                "command" => [
                    "report_type" => false,
                    "author" => "name" //TODO: make name
                ]
            ]
        );
    }

    /**
     * Метод выполняет запрос на печать чека прихода на ККТ.
     *
     * @param array $data Маасив параметров чека.
     *
     * @return false|string Ошибка выполнения запроса.|Строка JSON ответа на запрос.
     */
    public function printBill($data)
    {
        $data["app_id"] = $this->getAppID();
        $data["nonce"] = $this->nonce;
        $data["token"] = $this->token;
        $data["type"] = "printCheck";

        return $this->sendRequest(
            self::COMMAND_URL,
            $data
        );
    }

    /**
     * Метод выполняет запрос на печать чека возврата прихода на ККТ.
     *
     * @param array $data Маасив параметров чека.
     *
     * @return false|string Ошибка выполнения запроса.|Строка JSON ответа на запрос.
     */
    public function printRefundBill($data)
    {
        $data["app_id"] = $this->getAppID();
        $data["nonce"] = $this->nonce;
        $data["token"] = $this->token;
        $data["type"] = "printPurchaseReturn";

        return $this->sendRequest(
            self::COMMAND_URL,
            $data
        );
    }

    /**
     * Метод выполняет запрос на получение информации о состоянии системы.
     *
     * @return false|string Ошибка выполнения запроса.|Строка JSON ответа на запрос.
     */
    public function getSystemStatus()
    {
        return $this->sendRequest(
            self::GET_SYSTEM_STATUS,
            [
                "app_id" => $this->getAppID(),
                "nonce" => $this->nonce
            ]
        );
    }

    /**
     * Метод выполняет отпавку запроса на сервис.
     *
     * @param string $url Адрес отправки запрса.
     * @param array $params Массив параметров запроса.
     *
     * @return false|string Ошибка выполнения запроса.|Строка JSON ответа на запрос.
     */
    private function sendRequest($url, $params)
    {
        ksort($params);
        switch ($url) {
            case self::GET_TOKEN_URL:
                $isGet = true;
                break;
            case self::COMMAND_URL:
                $isGet = false;
                break;
            case self::GET_SYSTEM_STATUS:
                $isGet = true;
                break;
            default:
                var_dump($url);
                return false;
        }
        $cURL = curl_init();
        if ($isGet) {
            curl_setopt_array(
                $cURL,
                [
                    CURLOPT_URL => "https://".self::BASE_URL . $url . "?" . http_build_query($params),
                    CURLOPT_CUSTOMREQUEST => "GET",
                    CURLOPT_HTTPHEADER => [
                        "test: test",
                        "sign: " . $this->getSign($params),
                    ]
                ]
            );
        } else {
            curl_setopt_array(
                $cURL,
                [
                    CURLOPT_URL => "https://".self::BASE_URL . $url,
                    CURLOPT_CUSTOMREQUEST => "POST",
                    CURLOPT_POSTFIELDS => json_encode($params, JSON_UNESCAPED_UNICODE),
                    CURLOPT_HTTPHEADER => [
                        "Content-Type: application/json; charset=utf-8",
                        "accept: application/json",
                        "Content-Length: " . strlen(json_encode($params, JSON_UNESCAPED_UNICODE)),
                        "sign: " . $this->getSign($params),
                    ]
                ]
            );
        }

        curl_setopt_array(
            $cURL,
            [
                CURLOPT_VERBOSE => true,
                CURLOPT_HEADER => true,
                CURLINFO_HEADER_OUT => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]
        );
        print_r(curl_getinfo($cURL));
        $response = curl_exec($cURL);
        var_dump($response);
        return $response;
    }

    /**
     * Метод выполняет генерацию идентификатора запроса и сохраняет его в свойство объекта.
     */
    private function getNonce() //TODO:rename
    {
        $this->nonce = "salt_" . str_replace(".", "", microtime(true));
    }

    /**
     * Метод генерирует подпись запроса и возвращает подпись.
     *
     * @param array $params Параметры запроса для генерации на основе их подписи.
     *
     * @return string Подпись запроса.
     */
    private function getSign($params)
    {
        $sign = md5(
            json_encode(
                $params,
                JSON_UNESCAPED_UNICODE
            ) . $this->getSecretKey()
        );
        return $sign;
    }
}