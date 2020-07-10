<?
/*
global $SERVER_PORT,$HTTP_HOST;
if (($pos = strpos($HTTP_HOST,':')) !== false)
    $HTTP_HOST = substr($HTTP_HOST,0,$pos);
$SERVER_PORT = 80;

$_SERVER["SERVER_PORT"] = $SERVER_PORT;
$_SERVER["HTTP_HOST"] = $HTTP_HOST;
*/

// Конфиг
class rm_conf {
	const OPT_USER_GROUPS = array(10,11,12,14);			// ID групп [оптовых] пользователей, для которых нужно выводить розничные цены
}

use Bitrix\Main\Diag\Debug,
    Bitrix\Main\Loader,
    Bitrix\Main\EventManager;

//log
function customLog($message)
{
    $message = date('Y-m-d H:i:s').' '.$message."\n";
    file_put_contents($_SERVER['DOCUMENT_ROOT'].'/log.txt', $message, FILE_APPEND);

    return true;
}

function pre($data)
{
    global $USER;
    return $USER->isAdmin() ? '<pre>' . print_r($data, true) . '</pre>' : false;
}

/**
 * Функция перевода первой буквы слова в uppercase, а остальные буквы в lowercase.
 * @param $string
 * @return string
 */

function mb_ucfirst($string)
{
    return strtoupper(substr($string, 0, 1)) . substr($string, 1);
}

/**
 * Обработка заказов пришедших из 1С
 */
function externalOrder() {
    Debug::writeToFile(date("d.m.y H:i:s").' externalOrder() start >>>');
    \Bitrix\Main\Loader::includeModule("sale");

    $r = \Bitrix\Sale\Order::getList(array(
        'filter' => ['EXTERNAL_ORDER' => 'Y', '>=DATE_INSERT' => '13.12.2018'],
        'select' => ['EXTERNAL_ORDER', 'UPDATED_1C', 'ID']
    ));
    Debug::writeToFile('ORDERS COUNT: '.$r->getSelectedRowsCount());
    while ($arOrder = $r->fetch())
    {

        $order = \Bitrix\Sale\Order::load($arOrder["ID"]);

        //echo pre($order);

        Debug::writeToFile('ORDER_ID: ' . $order->getField('ID'));
        Debug::writeToFile('DATE_INSERT: ' . $order->getField('DATE_INSERT'));
        Debug::writeToFile('EXTERNAL_ORDER: ' . $order->getField('EXTERNAL_ORDER'));
        Debug::writeToFile('UPDATED_1C: ' . $order->getField('UPDATED_1C'));
        /**
         * Если заказ пришёл из 1С, то необходимо поменять поля так, что он был создан в Битрикс
         */
        if ($order->getField('EXTERNAL_ORDER') == 'Y')
        {
            try {
                $order->setField('EXTERNAL_ORDER', 'N');
                $order->setField('UPDATED_1C', 'N');
                $order->save();
            }catch (Exception $e){
                Debug::writeToFile("Ошибка: ".$e->getMessage());
            }


            Debug::writeToFile('Заказ из 1С изменён: ' . print_r([
                    "IS_NEW" => $order->getField('IS_NEW'),
                    "STATUS_ID" => $order->getField('STATUS_ID'),
                    "BX_ID (ID заказа в Битрикс)" => $order->getField('ID'),
                    "ID_1C (ID заказа в 1C)" => $order->getField('ID_1C'),
                    "VERSION_1C (Версия 1С)" => $order->getField('VERSION_1C'),
                    "EXTERNAL_ORDER (Заказ внешний?)" => $order->getField('EXTERNAL_ORDER'),
                    "UPDATED_1C (Обновлён в 1С?)" => $order->getField('UPDATED_1C'),
                    "VERSION (Версия в Битрикс)" => $order->getField('VERSION'),
                    "COMMENTS (Комментарий к заказу)" => $order->getField('COMMENTS')
                ], true)
            );

        }
    }


    Debug::writeToFile('>>> externalOrder() finish');
    return "externalOrder();";
}
if($_GET['test'] == 'test_orders')externalOrder();
/**
 *
 */



function rmdetalAgents($agentId = 0) {
    //Debug::writeToFile("rmdetalAgents($agentId) start " . date('H:i:s', time()));
    Loader::includeModule("rmdetal.tools");

    switch ($agentId) {
        case 1:
            //Обработка каталога пришедшего из 1С, выполняем действия над элементами
            \RMDetal\Tools\Catalog::agentSetElementFields();
            break;
        case 2:
            // Обработка каталога пришедшего из 1С, выполняем действия над разделами
            \RMDetal\Tools\Catalog::agentSetSectionFields();
            break;
    }

    //Debug::writeToFile("rmdetalAgents($agentId) finish " . date('H:i:s', time()));
    return "rmdetalAgents($agentId);";
}






//Debug::writeToFile('init.php start');
// OnSaleOrderCanceled
// https://dev.1c-bitrix.ru/api_d7/bitrix/sale/events/sale_setfields.php

// Важно!
// https://1c.1c-bitrix.ru/support/forum/forum26/topic94003/
// https://camouf.ru/blog-note/2486/
// https://smsdesign.com.ua/blog/bitrix/obmen-s-1s-s-realnymi-primerami.html

EventManager::getInstance()->addEventHandler('sale', 'OnSaleOrderCanceled', 'setOrderStatusCanceled');

/**
 * При отмене заказа ставим статус заказа "Отменён"
 * @param \Bitrix\Main\Event $event - событие
 */
function setOrderStatusCanceled(\Bitrix\Main\Event $event)
{
    //$entity = $event->getParameter("ENTITY");
    //$id = $entity->getField('ID');
    $parameters = $event->getParameters();
    $order = $parameters['ENTITY'];
    if($order->isCanceled())
    {
        // https://dev.1c-bitrix.ru/api_d7/bitrix/sale/statusbase/index.php
        // https://dev.1c-bitrix.ru/api_help/sale/classes/csaleorder/csaleorder__statusorder.f21c0322.php
        // Set status Canceled
        if (!CSaleOrder::StatusOrder($order->getId(), "C"))
            Debug::writeToFile("Ошибка установки статуса заказа \"Отмена\"");
    }
}

EventManager::getInstance()->addEventHandler("sale", "OnSaleOrderBeforeSaved", "onSaleOrderBeforeSaved");

/**
 * Обработчик заказа перед сохранением в БД, выполняет ряд функций:
 * 1. Проверяет Есть ли в комментарии заказа массив JSON, содержащий поля для изменения заказа и выполнения дополнительных
 * обработок
 * 2. Обрабатывает JSON массив:
 * 2.1.
 * @param \Bitrix\Main\Event $event - ещё несозданный заказ
 * @return \Bitrix\Main\EventResult
 */
 
function onSaleOrderBeforeSaved(\Bitrix\Main\Event $event)
{
    $debug = ''; //  __FUNCTION__
    $debugCur = '';

    $debugCur = $debug .= 'OnSaleOrderBeforeSaved' . PHP_EOL . PHP_EOL;

    // https://dev.1c-bitrix.ru/api_d7/bitrix/sale/events/order_saved.php
    $parameters = $event->getParameters();
    $order = $parameters['ENTITY'];
    $values = $parameters['VALUES'];
	$basket = $order->getBasket();
	$basketItems = $basket->getBasketItems();
	/*foreach ($basketItems as $basketItem) {
		echo $basketItem->getField('NAME') . ' - ' . $basketItem->getQuantity() . '<br />';
	}*/
	
    if (!$order instanceof \Bitrix\Sale\Order)
    {
        Debug::writeToFile('Неверный объект заказа');

        return new \Bitrix\Main\EventResult(
            \Bitrix\Main\EventResult::ERROR,
            new \Bitrix\Sale\ResultError('Неверный объект заказа', 'SALE_EVENT_WRONG_ORDER'),
            'sale'
        );
    }
    else
    {
        $arErrors = [];
        $orderId = $order->getId();
        $userId = $order->getUserId();
        $personTypeId = $order->getPersonTypeId();
        $isOrganization = $personTypeId == 2;
        $isNew = $orderId == 0 ? true : false;
        $dontCreateOrder = $order->getField('STATUS_ID') == 'T';
        $userProps = '';
        $isDebug = $order->getField('EXTERNAL_ORDER') == 'Y' && $isNew;
        $stepByStep = ($isDebug && false);
        $i = 0;

        foreach (['USER_ID', 'STATUS_ID', 'XML_ID', 'ID_1C', 'VERSION', 'EXTERNAL_ORDER', 'UPDATED_1C'] as $item) {
            $debugCur .= $debug .= $item . ' ' . $order->getField($item) . PHP_EOL . PHP_EOL;
        }
        if ($stepByStep) Debug::writeToFile(++$i . " " . $debugCur);
        $debugCur = '';

        $debugCur .= $debug .= '$comments before: ' . print_r($order->getField('COMMENTS'), true) . PHP_EOL . PHP_EOL;
        $debugCur .= $orderNewProperties = getArrayFromJson($order, true);
        $debugCur .= $debug .= '$orderNewProperties: ' . print_r($orderNewProperties, true) . PHP_EOL . PHP_EOL;
        $debugCur .= $debug .= '$comments after: ' . print_r($order->getField('COMMENTS'), true) . PHP_EOL . PHP_EOL;
        if ($stepByStep) Debug::writeToFile(++$i . " " . $debugCur);
        $debugCur = '';

        if(count($orderNewProperties))
        {
            /**
             * Описание JSON
             *  {
             *      "DEBUG": "a@resolve.su,a.redkin@rmdetal.ru", (String) Формируем лог дебага и отправляем по электронной почте по каждому заказу отдельное письмо
             *
             *      "FIELDS": {
             *      },
             *
             *      "PROPERTY": {
             *          // Физлицо
             *          "Фамилия": "Иванов", (String)
             *          "Имя": "Иван", (String)
             *          "Отчество": "Иванович", (String)
             *          "Ф.И.О.": "Иванов Иван Иванович", (String)
             *          "Телефон": "79106624992", (String)
             *          "E-Mail": "info@buransnab.ru", (String)
             *          "Индекс": "152909", (String)
             *          "Регион": "Ярославская обл", (String)
             *          "Район": "Рыбинский р-н", (String)
             *          "Город": "Рыбинск г", (String)
             *          "Населенный пункт": "", (String)
             *          "Улица": "Южная ул", (String)
             *          "Дом": "дом № 14", (String)
             *          "Корпус": "", (String)
             *          "Квартира": "кв.3", (String)
             *          "Адрес доставки": "152909, Ярославская обл, Рыбинский р-н, Рыбинск г, Южная ул, дом № 14, кв.3", (String)
             *          "Идентификатор отправления": "", (String)
             *          "Сумма доставки": "", (String)
             *
             *          // Юрлицо
             *          "ИНН": "7610103930", (String)
             *          "КПП": "761001001", (String)
             *          "Название компании": "СЕВЕРСНАБ ООО", (String)
             *          "Юридический адрес": "152900, Ярославская обл, Рыбинский р-н, Рыбинск г, Серова пр-кт, дом № 9А, кв.27", (String)
             *          "Адрес доставки": "Серова пр-кт", (String)
             *          "Контактное лицо (Ф.И.О)": "", (String)
             *          "E-Mail": "info@buransnab.ru", (String)
             *          "Телефон": "79106624992", (String)
             *          "Идентификатор отправления": "", (String)
             *          "Сумма доставки": "", (String)
             *
             *      }, (Object) Содержит обновлённые свойства заказа
             *
             *      "SHIPMENT": [
             *          {
             *              "ACCOUNT_NUMBER": "2728/1" - ID отгрузки
             *              "DELIVERY_ID": 1, (Integer) - ID службы доставки в Битрикс
             *              "CUSTOM_PRICE_DELIVERY": "Y" (String) - Флаг Y/N, устанавливается если указывается стоимость доставки вручную
             *              "CURRENCY": "RUB" (String) - Валюта
             *              "BASE_PRICE_DELIVERY": 1000 (Integer) - Базовая стоимость доставки
             *              "PRICE_DELIVERY": 1000 (Integer) - Стоимость доставки
             *              "TRACKING_NUMBER": "73182631738" (String) - Номер отслеживания доставки
             *          }, (Object) - Объект отгрузки
             *      ], (Array) - Массив отгрузок
             *
             *      "PAYMENT": [
             *          {
             *              "ACCOUNT_NUMBER": "2728/1" - ID оплаты
             *              "PAY_SYSTEM_ID": 1, (Integer) - ID платёжной системы в Битрикс
             *              "CURRENCY": "RUB" (String) - Валюта
             *              "SUM": 1000 (Integer) - Сумма
             *          }, (Object) - Объект оплаты
             *      ], (Array) - Массив оплат
             *
             *      "USER": {
             *          "ACTIVE": "Y", (String) - Y/N Активировать, деактивировать пользователя
             *          "EMAIL": "info@buransnab.ru", (String) - Email
             *          "NAME": "Иван", (String) - Имя
             *          "SECOND_NAME": "Иванович", (String) - Отчество
             *          "LAST_NAME": "Иванов", (String) - Фамилия
             *          "GROUPS_CODE": {
             *              "ADD": [
             *                  "DEALER", (String) - Код группы пользователей
             *              ], (Array) - Массив кодов групп пользователей для добавления пользователя в новую группу
             *              "DELETE": [
             *                  "DEALER", (String) - Код группы пользователей
             *              ], (Array) - Массив кодов групп пользователей для удаления пользователя из группы
             *          }, (Object) - Объект работы с правами пользовательских групп
             *      }, (Object) - Объект пользователя, содержит поля подлежащие изменению
             *
             *      "USER_PROPS": "UPDATE", (String) - Обработка профиля покупателя. UPDATE - обновить из свойств заказа, "CLEAR" - удалить все профили и создать новый из свойств заказа
             *
             *      "LAST_ACTIONS": [
             *          "DONT_CREATE_ORDER", (String) - Не создавать заказ
             *      ], (Array) - Массив специальных запросов
             *  }
             */

            $userProps = $orderNewProperties["USER_PROPS"];
            $propertyCollection = $order->getPropertyCollection();

            // Обновляем свойства заказа из JSON, по полю свойства NAME берём соответствующие элементы массива
            // После обработки получаем массив $orderNewProperties содержащий ключи без свойств
            $orderNewProperties = setOrderProperties($order, $orderNewProperties);
            $debugCur .= $debug .= '$orderNewProperties 2: ' . print_r($orderNewProperties, true) . PHP_EOL . PHP_EOL;
            if ($stepByStep) Debug::writeToFile(++$i . " " . $debugCur);
            $debugCur = '';

            // Даные ключи обрабатываются ниже
            foreach ($orderNewProperties as $propertyName => $propertyValue)
            {
                // В данном блоке выполняются операции над элементами JSON массива, которые не относятся к свойствам заказа

                switch ($propertyName)
                {
                    case 'DELIVERY_ID':
                        $shipmentCollection = $order->getShipmentCollection();
                        $service = Bitrix\Sale\Delivery\Services\Manager::getById(intval($propertyValue));

                        foreach ($shipmentCollection as $shipment)
                        {
                            if(!$shipment->isSystem())
                            {
                                $shipment->setFields(array(
                                    'DELIVERY_ID' => $service['ID'],
                                    'DELIVERY_NAME' => $service['NAME'],
                                ));
                            }
                        }
                        break;

                    case 'PAY_SYSTEM_ID':
                        $paymentCollection = $order->getPaymentCollection();
                        $service = Bitrix\Sale\PaySystem\Manager::getObjectById(intval($propertyValue));
                        //Debug::writeToFile('$paymentCollection: ' . print_r(count($paymentCollection), true));
                        //Debug::writeToFile('$paySystemService: ' . print_r($service, true));
                        foreach ($paymentCollection as $payment)
                        {
                            $payment->setFields(array(
                                'PAY_SYSTEM_ID' => $service->getField("PAY_SYSTEM_ID"),
                                'PAY_SYSTEM_NAME' => $service->getField("NAME"),
                            ));
                        }
                        break;

                    case 'DEALER':
                        // При добавлении нового дилера, добавляем пользователя в группу пользователей дилеры: 10
                        // Отменяем заказ
                        $dontCreateOrder = true;
                        $arGroups = CUser::GetUserGroup($userId);
                        $dealerGroupId = 10;

                        //Debug::writeToFile('DEALER $propertyValue: ' . print_r($propertyValue, true));
                        if($propertyValue == 'ON')
                        {
                            foreach ($propertyCollection as $propertyItem)
                            {
                                $dealerPropertyName = $propertyItem->getField("NAME");
                                $dealerPropertyValue = $propertyItem->getField("VALUE");

                                $rsUser = CUser::GetByID($order->getUserId());
                                $arUser = $rsUser->Fetch();

                                Debug::writeToFile('$dealerPropertyName: ' . $dealerPropertyName);
                                Debug::writeToFile('$rsUser: ' . print_r($arUser, 1));

                                switch ($dealerPropertyName)
                                {
                                    case "Название компании":
                                        // Получить Имя пользователя и прописать в название компании
                                        if (!$isOrganization) $dealerPropertyValue = $arUser["NAME"];
                                        break;
                                    case "E-Mail":
                                        // Добавить email пользователю
                                        //$user = new CUser;
                                        //$user->Update($arUser["ID"], ["EMAIL" => $dealerPropertyValue, "LOGIN" => $dealerPropertyValue]);
                                        //$strError = $user->LAST_ERROR;
                                        //Debug::writeToFile('$strError: ' . $strError);
                                        break;
                                    case "Телефон":
                                        // Добавить телефон пользователю
                                        $user = new CUser;
                                        $user->Update($order->getUserId(), ["WORK_PHONE" => $dealerPropertyName]);
                                        //$strError .= $user->LAST_ERROR;
                                        break;
                                }

                                if ($propertyItem->getField("VALUE") != $dealerPropertyValue)
                                    $propertyItem->setField("VALUE", $dealerPropertyValue);
                            }

                            $arGroups[] = 10;
                        } elseif ($propertyValue == 'OFF') {
                            //Debug::writeToFile('DEALER GROUPS OFF before' . print_r($arGroups, 1));
                            //Debug::writeToFile('DEALER GROUPS OFF $dealerGroupId' . print_r($dealerGroupId, 1));
                            if (($key = array_search($dealerGroupId, $arGroups)) !== false) {
                                unset($arGroups[$key]);
                            }
                            //Debug::writeToFile('DEALER GROUPS OFF after' . print_r($arGroups, 1));
                        }

                        //Debug::writeToFile('DEALER GROUPS' . print_r($arGroups, 1));

                        CUser::SetUserGroup($order->getUserId(), $arGroups);

                        //Debug::writeToFile('DEALER ID' . $order->getUserId());
                        //Debug::writeToFile('DEALER FINISH');
                        break;
                }
            }

            //$order->setField('COMMENTS', stristr($comments, '{', true));
            $debug .= 'DEALER NEW COMMENTS SET, JSON FININSH' . PHP_EOL . PHP_EOL;
            if ($stepByStep) Debug::writeToFile(++$i . " " . $debug);
        }

        // Устанавливаем свойства у нового заказа
        if($isNew)
        {
            setNewOrderProperties($order);
            $debugCur .= $debug .= 'setNewOrderProperties() OK' . PHP_EOL . PHP_EOL;
            $userProps = 'UPDATE';
            if ($stepByStep) Debug::writeToFile(++$i . " " . $debugCur);
            $debugCur = '';
        }

        // Обновляем профиль покупателя
        switch ($userProps)
        {
            case 'UPDATE':
                updateOrderUserProps($order);
                $debugCur .= $debug .= 'updateOrderUserProps() OK' . PHP_EOL . PHP_EOL;
                if ($stepByStep) Debug::writeToFile(++$i . " " . $debugCur);
                $debugCur = '';
                /*
                $userPropsId = [];
                $arOrder = ["USER_ID" => "ASC", "PERSON_TYPE_ID" => "ASC", "DATE_UPDATE" => "DESC"];
                $arFilter = ["USER_ID" => $userId, "PERSON_TYPE_ID" => $personTypeId];
                $i = 0;
                $r = CSaleOrderUserProps::GetList($arOrder, $arFilter);
                while ($arItem = $r->Fetch())
                {
                    //$arResult[] = $arItem;

                    $orderUserPropsId = $arItem["ID"];

                    if ($i++ > 0)
                    {
                        CSaleOrderUserProps::Delete($arItem["ID"]);
                    }
                    else
                    {
                        // Получаем текущие свойства заказа
                        $propertyCollection = $order->getPropertyCollection();
                        $arPropertyCollection = $propertyCollection->getArray();
                        $orderUserPropsValueFields = [];
                        foreach ($arPropertyCollection["properties"] as $propertyItem)
                        {
                            $propertyName = $propertyItem["NAME"];
                            $propertyId = $propertyItem["ID"];
                            $propertyValue = $propertyItem["VALUE"][0];

                            if(!empty($propertyValue)) $orderUserPropsValueFields[$propertyName] = ["ID" => $propertyId, "VALUE" => $propertyValue];
                        }

                        // Изменим название профиля покупателя
                        Debug::writeToFile('Изменим название профиля покупателя' . print_r([$orderUserPropsId,
                                [
                                    "NAME" => (
                                    $orderUserPropsValueFields["Название компании"]["VALUE"] ?
                                        $orderUserPropsValueFields["Название компании"]["VALUE"] :
                                        $orderUserPropsValueFields["Ф.И.О."]["VALUE"]
                                    ),
                                    "USER_ID" => $userId,
                                    "PERSON_TYPE_ID" => $personTypeId,
                                ]], 1)
                        );

                        if(!empty($orderUserPropsValueFields) && $orderUserPropsId > 0 && $userId > 0 && $personTypeId > 0)
                        {
                            $res = CSaleOrderUserProps::Update(
                                $orderUserPropsId,
                                [
                                    "NAME" => (
                                    $orderUserPropsValueFields["Название компании"]["VALUE"] ?
                                        $orderUserPropsValueFields["Название компании"]["VALUE"] :
                                        $orderUserPropsValueFields["Ф.И.О."]["VALUE"]
                                    ),
                                    "USER_ID" => $userId,
                                    "PERSON_TYPE_ID" => $personTypeId,
                                ]
                            );
                            Debug::writeToFile('Изменим название профиля покупателя, результат: ' . print_r($res, 1));
                        }

                        $db_propVals = CSaleOrderUserPropsValue::GetList(array("USER_PROPS_ID" => "DESC"), Array("USER_PROPS_ID" => $orderUserPropsId));
                        while ($arPropVals = $db_propVals->Fetch())
                        {
                            $res = CSaleOrderUserPropsValue::Update(
                                $arPropVals["ID"],
                                [
                                    "USER_PROPS_ID" =>  $arPropVals["USER_PROPS_ID"],
                                    "ORDER_PROPS_ID" => $arPropVals["PROP_ID"],
                                    "NAME" => $arPropVals["NAME"],
                                    "VALUE" => $orderUserPropsValueFields[$arPropVals["NAME"]]["VALUE"]
                                ]
                            );
                            unset($orderUserPropsValueFields[$arPropVals["NAME"]]);
                        }

                        foreach ($orderUserPropsValueFields as $newUserPropertyName => $newUserPropertyValue) {
                            $resAdd = CSaleOrderUserPropsValue::Add(
                                [
                                    "USER_PROPS_ID" => $orderUserPropsId,
                                    "ORDER_PROPS_ID" => $newUserPropertyValue["ID"],
                                    "NAME" => $newUserPropertyName,
                                    "VALUE" => $newUserPropertyValue["VALUE"]
                                ]
                            );
                        }
                    }
                }
                */
                break;
        }

        $debugCur .= $debug .= 'OnSaleOrderBeforeSaved finish' . PHP_EOL . PHP_EOL;
        if ($stepByStep) Debug::writeToFile(++$i . " " . $debugCur);

        // Отправить сообщение

        //$orderNewProperties["DEBUG"] .= ",a.lyrmin@gmail.com";
        if ($isDebug && strlen($orderNewProperties["DEBUG"])) {
            $arEventFields = array(
                "EMAIL_TO"            => $orderNewProperties["DEBUG"],
                "TITLE"                => "Дебаг " . date("d.m.Y H:i:s") . ", заказ: " . $orderId,
                "MESSAGE"             => $debug,
            );
            Bitrix\Main\Mail\Event::send(array(
                "EVENT_NAME" => "DEBUG",
                "LID" => $order->getField("LID"),
                "C_FIELDS" => $arEventFields,
            ));
        }

        if ($isDebug && !$stepByStep) Debug::writeToFile($debug);

        // Остановить создание заказа
        //$dontCreateOrder = false;
        if($dontCreateOrder)
        {
            /*
            https://dev.1c-bitrix.ru/learning/course/index.php?COURSE_ID=43&LESSON_ID=3113
            Твоя задача вернуть объект \Bitrix\Main\EventResult
            https://dev.1c-bitrix.ru/api_d7/bitrix/main/entity/eventresult/index.php
            с ошибкой. В этом случае метод save() заказа прекратит выполнение и вернет ошибку. Само событие OnSaleOrderBeforeSaved верное. Должно отрабатывать
            */

            return new \Bitrix\Main\EventResult(
                \Bitrix\Main\EventResult::ERROR,
                new \Bitrix\Sale\ResultError('Удаление технического заказа', 'SALE_EVENT_TECH_ORDER'),
                'sale'
            );
        }
    }
}

/**
 * Устанавливаем новые значения свойств заказа
 * @param \Bitrix\Sale\Order $order
 */
function setNewOrderProperties(\Bitrix\Sale\Order &$order)
{
    $orderId = $order->getId();
    $userId = $order->getUserId();
    $i = 0;

    $propertyCollection = $order->getPropertyCollection();
    $address = array();
    foreach ($propertyCollection as $propertyItem)
    {
        $propertyName = $propertyItem->getField("NAME");
        $propertyValue = $propertyItem->getField("VALUE");
        $propertyId = $propertyItem->getPropertyId();
        $propertyCode = $propertyItem->getField("CODE");

        if(!isset($userName)) $userName = [];
        if(!isset($addressNew)) $addressNew = [];

        switch ($propertyName) {
            case 'Идентификатор отправления':
                $shipmentCollection = $order->getShipmentCollection();
                foreach ($shipmentCollection as $shipment)
                {
                    if (!$shipment->isSystem() && $propertyValue)
                    {
                        //$shipment->setField('TRACKING_NUMBER', $propertyValue);
                    }
                }
                break;
            case 'Фамилия':
            case 'Имя':
            case 'Отчество':
                $userName[$propertyCode] = mb_ucfirst(strtolower($propertyValue));
                if ($propertyValue != $userName[$propertyCode]) $propertyItem->setField("VALUE", $userName[$propertyCode]);
                break;
            case 'Ф.И.О.':
                $propertyItem->setField("VALUE", implode(' ', $userName));
                break;
            case 'Индекс':
            case 'Регион':
            case 'Район':
            case 'Город':
            case 'Населенный пункт':
            case 'Улица':
            case 'Дом':
            case 'Корпус':
            case 'Квартира':
                $address[$propertyCode] = mb_ucfirst(strtolower($propertyValue));
                if ($propertyValue != $address[$propertyCode]) $propertyItem->setField("VALUE", $address[$propertyCode]);
                break;
            case 'Адрес доставки':
                if(!empty($address)) $propertyItem->setField("VALUE", implode(',', $address));
                break;
        }
    }
}


/**
 * @param \Bitrix\Sale\Order $order - Заказ
 * @param array $arNewProperties - Массив новых значений свойств заказа
 * @return array|bool - Массив оставшихся элементов свойств
 */
function setOrderProperties(\Bitrix\Sale\Order &$order, $arNewProperties = []) {
    $r = false;
    if (count($arNewProperties)) {
        $userId = $order->getUserId();
        $propertyCollection = $order->getPropertyCollection();

        foreach ($propertyCollection as $propertyItem)
        {
            $propertyName = $propertyItem->getField("NAME");
            $propertyValue = $arNewProperties[$propertyName];
            if(in_array($propertyName, array_keys($arNewProperties)))
            {
                $propertyItem->setField("VALUE", $propertyValue);

                // Удаляем из JSON обновлённый элемент
                unset($arNewProperties[$propertyName]);
            }



            switch ($propertyName) {
                case 'Идентификатор отправления':
                    $shipmentCollection = $order->getShipmentCollection();
                    foreach ($shipmentCollection as $shipment)
                    {
                        if (!$shipment->isSystem() && $propertyValue)
                        {
                            $shipment->setField('TRACKING_NUMBER', $propertyValue);
                        }
                    }
                    break;
            }
        }

        return $arNewProperties;
    }
    return $r;
}

/**
 * Обновляем профиль покупателя
 * @param \Bitrix\Sale\Order $order
 * @return array
 */
function updateOrderUserProps(\Bitrix\Sale\Order $order)
{
    $res = [];
    $orderId = $order->getId();
    $userId = $order->getUserId();
    $personTypeId = $order->getPersonTypeId();

    $result = [
        "OREDER_ID" => $orderId,
        "USER_ID" => $userId,
        "PERSON_TYPE_ID" => $personTypeId
    ];

    if ($userId > 0 && $personTypeId > 0)
    {
        $userPropsId = [];
        $arOrder = ["DATE_UPDATE" => "DESC"];
        $arFilter = ["USER_ID" => $userId];
        $i = 0;
        $r = CSaleOrderUserProps::GetList($arOrder, $arFilter);
        while ($arItem = $r->Fetch())
        {

            $orderUserPropsId = $arItem["ID"];

            if ($i++ > 0)
            {
                CSaleOrderUserProps::Delete($arItem["ID"]);
            }
            else
            {
                // Получаем текущие свойства заказа
                $propertyCollection = $order->getPropertyCollection();
                $arPropertyCollection = $propertyCollection->getArray();
                foreach ($arPropertyCollection["properties"] as $propertyItem)
                {
                    $propertyName = $propertyItem["NAME"];
                    $propertyId = $propertyItem["ID"];
                    $propertyValue = $propertyItem["VALUE"][0];

                    if(!empty($propertyValue)) $orderUserPropsValueFields[$propertyName] = ["ID" => $propertyId, "VALUE" => $propertyValue];
                }

                if(!empty($orderUserPropsValueFields) && $orderUserPropsId > 0 && $userId > 0 && $personTypeId > 0)
                {
                    $res = CSaleOrderUserProps::Update(
                        $orderUserPropsId,
                        [
                            "NAME" => (
                            $orderUserPropsValueFields["Название компании"]["VALUE"] ?
                                $orderUserPropsValueFields["Название компании"]["VALUE"] :
                                $orderUserPropsValueFields["Ф.И.О."]["VALUE"]
                            ),
                            "USER_ID" => $userId,
                            "PERSON_TYPE_ID" => $personTypeId,
                        ]
                    );

                    // Обновляем пользователя
                    $user = new CUser;
                    $userFields = [
                        "EMAIL"             => $orderUserPropsValueFields["E-Mail"]["VALUE"],
                        "LOGIN"             => $orderUserPropsValueFields["E-Mail"]["VALUE"],
                    ];

                    if (strlen($orderUserPropsValueFields["Название компании"]["VALUE"])) {
                        $userFields["NAME"] = $orderUserPropsValueFields["Название компании"]["VALUE"];
                        $userFields["SECOND_NAME"] = '';
                        $userFields["LAST_NAME"] = '';
                    } else {
                        $userFields["LAST_NAME"] = $orderUserPropsValueFields["Фамилия"]["VALUE"];
                        $userFields["NAME"] = $orderUserPropsValueFields["Имя"]["VALUE"];
                        $userFields["SECOND_NAME"] = $orderUserPropsValueFields["Отчество"]["VALUE"];
                    }

                    $user->Update($userId, $userFields);
                    $strError = $user->LAST_ERROR;
                }

                CSaleOrderUserPropsValue::DeleteAll($arItem["ID"]);

                $db_propVals = CSaleOrderUserPropsValue::GetList(array("USER_PROPS_ID" => "DESC"), Array("USER_PROPS_ID" => $orderUserPropsId));
                while ($arPropVals = $db_propVals->Fetch())
                {
                    $res = CSaleOrderUserPropsValue::Update(
                        $arPropVals["ID"],
                        [
                            "USER_PROPS_ID" =>  $arPropVals["USER_PROPS_ID"],
                            "ORDER_PROPS_ID" => $arPropVals["PROP_ID"],
                            "NAME" => $arPropVals["NAME"],
                            "VALUE" => $orderUserPropsValueFields[$arPropVals["NAME"]]["VALUE"]
                        ]
                    );
                    unset($orderUserPropsValueFields[$arPropVals["NAME"]]);
                }
                foreach ($orderUserPropsValueFields as $newUserPropertyName => $newUserPropertyValue) {
                    $resAdd = CSaleOrderUserPropsValue::Add(
                        [
                            "USER_PROPS_ID" => $orderUserPropsId,
                            "ORDER_PROPS_ID" => $newUserPropertyValue["ID"],
                            "NAME" => $newUserPropertyName,
                            "VALUE" => $newUserPropertyValue["VALUE"]
                        ]
                    );
                }
            }
        }

        // Добавляем новый профиль, если профилей нет
        if (!$r->SelectedRowsCount()) {
            addOrderUserProps($order);
        }
    }

    return $result;
}

/**
 * Из комментария получаем JSON массив
 * @param \Bitrix\Sale\Order $order - заказ
 * @param bool $deleteJson - Флаг удаления Json из комментария
 * @return array
 */
function getArrayFromJson(\Bitrix\Sale\Order &$order, $deleteJson = true) {
    $r = [];
    $comments = $order->getField('COMMENTS');
    //Debug::writeToFile('$comments: ' . print_r($comments, true));

    preg_match_all('/\{(?:[^{}]|(?R))*\}/x', $comments, $arJson);
    $arJson = $arJson[0];
    foreach ($arJson as $json) {
        $array = \Bitrix\Main\Web\Json::decode($json);
        $r = array_merge($r, $array);
    }

    if ($deleteJson) $order->setField('COMMENTS', stristr($comments, '{', true));

    return $r;
}

/**
 * Добавляем профиль для нового покупателя из технического заказа
 * @param \Bitrix\Sale\Order $order заказ
 */
function addOrderUserProps(\Bitrix\Sale\Order $order)
{
    $orderId = $order->getId();
    $userId = $order->getUserId();
    $personTypeId = $order->getPersonTypeId();
    //$isOrganization = $personTypeId == 2;
    $isNew = $orderId == 0 ? true : false;
    //$userId = 1042;

    // https://rmdetal.ru/bitrix/admin/sale_buyers_profile.php?USER_ID=1042&lang=ru&buyers-subscription-list=page-1-size-20
    if ($isNew) {
        $propertyCollection = $order->getPropertyCollection();
        $arPropertyCollection = $propertyCollection->getArray();

        foreach ($arPropertyCollection["properties"] as $propertyItem)
        {
            $propertyName = $propertyItem["NAME"];
            $propertyId = $propertyItem["ID"];
            $propertyValue = $propertyItem["VALUE"][0];

            $orderUserPropsValueFields[$propertyName] = ["ID" => $propertyId, "VALUE" => $propertyValue];
        }

        $orderUserPropsId = CSaleOrderUserProps::Add(
            [
                "NAME" => (
                    $orderUserPropsValueFields["Название компании"]["VALUE"] ?
                        $orderUserPropsValueFields["Название компании"]["VALUE"] :
                        $orderUserPropsValueFields["Ф.И.О."]["VALUE"]
                    ),
                "USER_ID" => $userId,
                "PERSON_TYPE_ID" => $personTypeId,
            ]
        );

        foreach ($orderUserPropsValueFields as $newUserPropertyName => $newUserPropertyValue) {
            $resAdd = CSaleOrderUserPropsValue::Add(
                [
                    "USER_PROPS_ID" => $orderUserPropsId,
                    "ORDER_PROPS_ID" => $newUserPropertyValue["ID"],
                    "NAME" => $newUserPropertyName,
                    "VALUE" => $newUserPropertyValue["VALUE"]
                ]
            );
        }

        //echo pre('PROFILE ID ' . $orderUserPropsId);
    }
}












EventManager::getInstance()->addEventHandler('sale', 'OnSaleOrderSaved', 'onSaleOrderSaved');

function onSaleOrderSaved(\Bitrix\Main\Event $event)
{
    /** @var Order $order */
    $order = $event->getParameter("ENTITY");
    //$oldValues = $event->getParameter("VALUES");
    $isNew = $event->getParameter("IS_NEW");
    $userId = $order->getUserId();
    $personTypeId = $order->getPersonTypeId();
    $isDebug = ($order->getField("STATUS_ID") == "T");

    if ($isDebug) Debug::writeToFile('onSaleOrderSaved start');
    if ($isDebug) Debug::writeToFile('ORDER_ID ' . $order->getId());
    if ($isDebug) Debug::writeToFile('$userId ' . $userId);
    if ($isDebug) Debug::writeToFile('$personTypeId ' . $personTypeId);
    if ($isDebug) Debug::writeToFile('onSaleOrderSaved finish');
}






EventManager::getInstance()->addEventHandler('main', 'OnBeforeUserAdd', 'onBeforeUserAddHandler');

function onBeforeUserAddHandler(&$arUser)
{
    //Debug::writeToFile('onBeforeUserAddHandler');

    switch ($_SERVER['PHP_SELF'])
    {
        case '/bitrix/admin/sale_order_create.php':
            $arUser['LAST_NAME'] = mb_ucfirst(strtolower($_POST['PROPERTIES']['26']));
            $arUser['NAME'] = mb_ucfirst(strtolower($_POST['PROPERTIES']['27']));
            $arUser['SECOND_NAME'] = mb_ucfirst(strtolower($_POST['PROPERTIES']['28']));

            /*
            \Bitrix\Main\Mail\Event::send(array(
                "EVENT_NAME" => "USER_INFO", // NEW_USER
                "LID" => "s1",
                "C_FIELDS" => array(
                    "EMAIL" => $arUser['EMAIL'],
                    "USER_ID" => 42
                ),
            ));
            */
            break;

        case '/personal/order/make/index.php':
            if(strlen($_POST['ORDER_PROP_26']) > 0) $arUser['LAST_NAME'] = mb_ucfirst(strtolower($_POST['ORDER_PROP_26']));
            if(strlen($_POST['ORDER_PROP_27']) > 0) $arUser['NAME'] = mb_ucfirst(strtolower($_POST['ORDER_PROP_27']));
            if(strlen($_POST['ORDER_PROP_28']) > 0) $arUser['SECOND_NAME'] = mb_ucfirst(strtolower($_POST['ORDER_PROP_28']));
            break;

        default:
            $arUser['LAST_NAME'] = mb_ucfirst(strtolower($arUser['LAST_NAME']));
            $arUser['NAME'] = mb_ucfirst(strtolower($arUser['NAME']));
            $arUser['SECOND_NAME'] = mb_ucfirst(strtolower($arUser['SECOND_NAME']));
            break;
    }

    $arUser['LOGIN'] = $arUser['EMAIL'];
    $_SESSION['NEW_USER_PASSWORD'][$arUser['EMAIL']] = $arUser['PASSWORD'];

    Debug::writeToFile('onBeforeUserAddHandler $arUser');
    Debug::writeToFile($arUser);

    //Debug::writeToFile('$_SERVER');
    //Debug::writeToFile($_SERVER);

    /*
    $arUser
    Array
    (
        [LOGIN] => las7@yandex.ru
        [NAME] => 222
        [LAST_NAME] => 111
        [PASSWORD] => i8B8Sc6G
        [CONFIRM_PASSWORD] => i8B8Sc6G
        [EMAIL] => las7@yandex.ru
        [GROUP_ID] => Array
            (
                [0] => 6
            )

        [ACTIVE] => Y
        [LID] => s1
        [PERSONAL_PHONE] => 4444444
        [PERSONAL_ZIP] =>
        [PERSONAL_STREET] =>
    )

    $_POST
    Array
    (
        [sessid] => bc1da27e3b60117f43159e18f2031522
        [soa-action] => saveOrderAjax
        [location_type] => code
        [BUYER_STORE] => 0
        [ORDER_PROP_1] => 111 222 333
        [ORDER_PROP_2] => las7@yandex.ru
        [ORDER_PROP_3] => 4444444
        [ORDER_DESCRIPTION] =>
        [PERSON_TYPE] => 1
        [PERSON_TYPE_OLD] => 1
        [DELIVERY_ID] => 3
        [PAY_SYSTEM_ID] => 4
        [save] => Y
    )
     */

}

EventManager::getInstance()->addEventHandler('main', 'OnAfterUserAdd', 'onAfterUserAddHandler');

function onAfterUserAddHandler(&$arFields)
{
    //Debug::writeToFile('onAfterUserAddHandler');

    switch ($_SERVER['PHP_SELF'])
    {
        case '/bitrix/admin/sale_order_create.php':

            $arMess = false;
            $res_site = \CSite::GetByID($_POST["LID"]);
            if($res_site_arr = $res_site->Fetch())
                $arMess = IncludeModuleLangFile($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/admin/user_edit.php', $res_site_arr["LANGUAGE_ID"], true);

            $text = ($arMess !== false? $arMess["ACCOUNT_INSERT"] : GetMessage("ACCOUNT_INSERT"));

            CUser::SendUserInfo($arFields['ID'], $arFields["LID"], $text, true);

            /*
            \Bitrix\Main\Mail\Event::send(array(
                "EVENT_NAME" => "USER_INFO", // NEW_USER
                "LID" => "s1",
                "C_FIELDS" => array(
                    "EMAIL" => $arUser['EMAIL'],
                    "USER_ID" => 42
                ),
            ));
            */
            break;
    }

    //Debug::writeToFile('$arFields');
    //Debug::writeToFile($arFields);
}

EventManager::getInstance()->addEventHandler('main', 'OnBeforeEventSend', 'onBeforeEventSendHandler');

function onBeforeEventSendHandler(&$arFields, &$arTemplate)
{
    //Debug::writeToFile('onBeforeEventSendHandler');
    //Debug::writeToFile('$arFields');
    //Debug::writeToFile($arFields);
    //Debug::writeToFile('$arTemplate');
    //Debug::writeToFile($arTemplate);

    switch ($arTemplate['EVENT_NAME'])
    {
        case 'USER_INFO':
            $dbUser = CUser::GetByID($arFields['USER_ID']);
            $arFields += $dbUser->Fetch();
            $arFields['PASSWORD'] = $_SESSION['NEW_USER_PASSWORD'][$arFields['EMAIL']];
            unset($_SESSION['NEW_USER_PASSWORD'][$arFields['EMAIL']]);
            break;
    }
}

function isUserAgentPageSpeed()
{
    // 2019 year
    $pageSpeedUserAgent = 'Chrome-Lighthouse';

    if (!isset($_SERVER['HTTP_USER_AGENT']) || stripos($_SERVER['HTTP_USER_AGENT'], $pageSpeedUserAgent) === false) {
        return true;
    }

    return false;
}

//Debug::writeToFile('init.php finish');


//вырезаем type="text/javascript" 
AddEventHandler("main", "OnEndBufferContent", "removeType");

function removeType(&$content)
{
    $content = replace_output($content);
}
function replace_output($d)
{
    return str_replace(' type="text/javascript"', "", $d);
}



//AddEventHandler("sale", "OnOrderUpdate", "add1cElems");
AddEventHandler("sale", "OnBasketAdd", "add1cProductAdd");
function add1cProductAdd($id, $arFields){
    //AddMessage2Log("<<< TEST 1C_PRODUCT_ADD >>> \n".$id."\n".print_r($arFields,true),"add1cElems");
    \Bitrix\Main\Loader::includeModule("iblock");
    \Bitrix\Main\Loader::includeModule("sale");
    $item = $arFields;
    $iblock_id = 42;
    if($item['MODULE'] != 'catalog' /*&& $item["ORDER_ID"]*/){
        $el = new CIBlockElement;
        $PROP = array();
        $PROP["VAT"] = $item["VAT_RATE"];
        $PROP["PRICE"] = $item["PRICE"];
        $PROP["PRODUCT_ID"] = $item["PRODUCT_ID"];
        $PROP["ID"] = $item["ID"];
        $PROP["ORDER_ID"] = $item["ORDER_ID"];
        $PROP["DETAIL_PAGE_URL"] = $item["DETAIL_PAGE_URL"];
        $PROP["MODULE"] = $item["MODULE"];
        $PROP["QUANTITY"] = $item["QUANTITY"];
        $arLoadProductArray = Array(
            //"MODIFIED_BY"    => $USER->GetID(), // элемент изменен текущим пользователем
            "IBLOCK_SECTION_ID" => false,          // элемент лежит в корне раздела
            "IBLOCK_ID"      => $iblock_id,
            "PROPERTY_VALUES"=> $PROP,
            "NAME"           => $item["NAME"],
            "ACTIVE"         => "Y",            // активен  "PREVIEW_TEXT"   => "текст для списка элементов",
            "DETAIL_TEXT"    => "текст для детального просмотра",
            //"DETAIL_PICTURE" => CFile::MakeFileArray($_SERVER["DOCUMENT_ROOT"]."/image.gif")
        );
        $PRODUCT_ID = $el->Add($arLoadProductArray);
        if($PRODUCT_ID){
            // Получаем налог
            $vat_db = CCatalogVat::GetListEx(
                array('C_SORT' => 'ASC'),
                array("RATE" => $item["VAT_RATE"] * 100)
            );
            if($ar_vat = $vat_db->GetNext()){
                // Добавляетм параметры товара
                $arFields = array(
                    "ID" => $PRODUCT_ID,
                    "VAT_ID" => $ar_vat['ID'], //выставляем тип ндс (задается в админке)
                    "VAT_INCLUDED" => $item["VAT_INCLUDED"], //НДС входит в стоимость
                    "QUANTITY" => $item["QUANTITY"]
                );
                if(CCatalogProduct::Add($arFields)){
                    // Установим для товара цену
                    $PRICE_TYPE_ID = 1;//$item["PRICE_TYPE_ID"];

                    $arFields = Array(
                        "PRODUCT_ID" => $PRODUCT_ID,
                        "CATALOG_GROUP_ID" => $PRICE_TYPE_ID,
                        "PRICE" => $item["PRICE"],
                        "CURRENCY" => $item["CURRENCY"]
                    );

                    $res = CPrice::GetList(
                        array(),
                        array(
                            "PRODUCT_ID" => $PRODUCT_ID,
                            "CATALOG_GROUP_ID" => $PRICE_TYPE_ID
                        )
                    );

                    if ($arr = $res->Fetch())
                    {
                        CPrice::Update($arr["ID"], $arFields);
                    }
                    else
                    {
                        CPrice::Add($arFields);
                    }

                }
            }
            // Добавляем в заказ

            // Удаляем из заказа товар
            Debug::writeToFile("add1cProductAdd() start [".$id."] >>>\n".print_r($item,true));
            $order__ = new Racoon($item["ORDER_ID"]);
            $order__->delItem($item["PRODUCT_ID"]);
            //Добавляем в заказ новый
            Debug::writeToFile("Добавляем товар в заказ ".print_r($order__->getItem($PRODUCT_ID, $item["QUANTITY"]), true));
            // Сохраняем
            $order__->itemSave();

            Debug::writeToFile("<<< add1cProductAdd() end");
        }

    }

}
/*function add1cElems($id, $arFields){

    $iblock_id = 42;
    AddMessage2Log("<<< TEST >>> \n".$id."\n".print_r($arFields,true),"add1cElems");
    if ($arFields['BASKET_ITEMS']){
        foreach ($arFields['BASKET_ITEMS'] as $item){

        }
    }

    return;

}*/
if (!defined('BX_AGENTS_LOG_FUNCTION')) {
    define('BX_AGENTS_LOG_FUNCTION', 'OlegproAgentsLogFunction');

    function OlegproAgentsLogFunction($arAgent, $point)
    {
        /*$allowedAgentNames = [
            'CCatalogExport::PreGenerateExport',
        ];

        $isAllowAgent = false;

        if (isset($arAgent['NAME'])) {

            foreach ($allowedAgentNames as $allowedAgentName) {
                if (strpos($arAgent['NAME'], $allowedAgentName) !== false) {
                    $isAllowAgent = true;

                    break;
                }
            }

        }

        if ($isAllowAgent) {*/
            @file_put_contents(
                $_SERVER['DOCUMENT_ROOT'] . '/agents_executions_points.log',
                (
                    PHP_EOL . date('d-m-Y H:i:s') . PHP_EOL .
                    print_r($point, 1) . PHP_EOL .
                    print_r($arAgent, 1) . PHP_EOL
                ),
                FILE_APPEND
            );
        //}

    }

}

// Оплата заказа произведена
AddEventHandler("sale", "OnSalePayOrder", "RacoonOnSalePayOrder");
function RacoonOnSalePayOrder($id, $val){
    \Bitrix\Main\Loader::includeModule("sale");
    global $USER;
    Debug::writeToFile("RacoonOnSalePayOrder() Start >>>\nID= ".$id." VAL= ".$val);
    $r = Bitrix\Sale\Order::getList(array(
        'filter' => ['ID' => $id],
        'select' => ['*'],
    ));
    while ($arOrder = $r->fetch())
    {
        Debug::writeToFile("\n\tORDER ".print_r($arOrder, true));
        // Оплата Сбербанк прошла
        if($arOrder['PAY_SYSTEM_ID'] == 4 && $arOrder['PAYED'] == 'Y' && $arOrder['STATUS_ID'] == 'PP'){

            $bill = new RacoonBill();
            $bill->Prepare($arOrder);
        }
    }

    Debug::writeToFile("<<< RacoonOnSalePayOrder() End");
}

include_once __DIR__."OpenApiConnector.php";
use OpenApiConnector as CONNECTOR;

/**
 * Class RacoonBill
 * Печать чеков
 */
Class RacoonBill{
    private $connector;
    function __construct()
    {   //App ID:
        $app_id = "ffc1d1e1-63a2-4bd9-a458-5fccdc58f384";
        //Секретный ключ:
        $app_key = "BN52Sj13ygo8ATJLl9QeUsiFadcxhzwI";
        $this->connector = new CONNECTOR($app_id,$app_key); // Создание экземпляра класса
    }
    function Prepare($arOrder){
        global $USER;
        $ar_rez['ORDER'] = $arOrder;
        $rsUser = CUser::GetByID($arOrder['USER_ID']);
        $ar_rez['USER'] = $rsUser->Fetch();
        $this->PrintFirst($ar_rez);
    }
    function PrintFirst($ar_params){
        $billArray = [ // Массив с данными чека.
            "command" => [ // Массив с данными команды.
                "author" => "Михаил Ефремов", // (String) Имя кассира (Будет пробито на чеке).
                "smsEmail54FZ" => $ar_params['USER']['EMAIL'], // (String) Телефон или e-mail покупателя.
                "c_num" => 1111222333, // (int) Номер чека.
                "payed_cash" => 0.00, // (float) Сумма оплаты наличными (Не более 2-х знаков после точки).
                "payed_cashless" => $ar_params['ORDER']['SUM_PAID'] , // (float) Сумма оплаты безаличным рассчетом (Не более 2-х знаков после точки).
                "goods" => [ // Массив с позициями в чеке.
                    [
                        "count" => 2, // (float) Количество товара (Не более 3-х знаков после точки).
                        "price" => 500, // (float) Стоимость товара (Не более 2-х знаков после точки).
                        "sum" => 1000, // (float) Сумма товарной позиции (Не более 2-х знаков после точки).
                        "name" => "ТОвар 1", // (String) Наименование товара (Будет пробито на чеке).
                        "nds_value" => 18, // (int) Значение налога.
                        "nds_not_apply" => false // (bool) Используется ли НДС для товара.
                    ],
                    [
                        "count" => 1,
                        "price" => 500.10,
                        "sum" => 500.10,
                        "name" => "Товар 2",
                        "nds_value" => 18,
                        "nds_not_apply" => true
                    ]
                ]
            ]
        ];
        $this->connector->printBill($billArray); // Команда на печать чека прихода.
    }
}

/**
 * Class Racoon
 * Подмена товаров в закзе
 */
Class Racoon{
    private $basket, $order, $IBLOCK_ID, $item;
    function __construct($order_id){
        \Bitrix\Main\Loader::includeModule("sale");
        \Bitrix\Main\Loader::includeModule("iblock");
        $this->IBLOCK_ID = 42;
        $this->order = \Bitrix\Sale\Order::load($order_id);
        $this->basket = $this->order->getBasket();
    }

    /**
     * @param $prod_id int ID Товара (PRODUCT_ID)
     *  Удаляет товар из заказа
     */
    function delItem($prod_id){
        Debug::writeToFile("Ищем товар [PRODUCT_ID]=".$prod_id);
        $items = $this->basket->getBasketItems();
        foreach ($items as $key => $item) {
            if($item->getField('PRODUCT_ID') == $prod_id){
                Debug::writeToFile("Удаляем товар [PRODUCT_ID]=".$prod_id." Из заказа ".$this->order->getField("ID"));
                $item->delete();
                $refreshStrategy = \Bitrix\Sale\Basket\RefreshFactory::create(\Bitrix\Sale\Basket\RefreshFactory::TYPE_FULL);
                $this->basket->refresh($refreshStrategy);
                $this->basket->save();
            }
        }
    }
    function getItem($id, $q=1){
        global $USER;
        Debug::writeToFile("Ищем товар в инфоблоке ".$this->IBLOCK_ID." [PRODUCT_ID]=".$id);
        $dbItems = \Bitrix\Iblock\ElementTable::getList(array(
            'select' => array('ID', 'NAME', 'IBLOCK_ID'),
            'filter' => array('IBLOCK_ID' => $this->IBLOCK_ID, "ID" => $id)
        ));
        $count = $dbItems->getSelectedRowsCount();
        Debug::writeToFile("Найдено ".$count);
        if($count == 0)
            return false;
        $ar_item = $dbItems->Fetch();
        $arPrice = CCatalogProduct::GetOptimalPrice($id, $q, $USER->GetUserGroupArray());
        //print_r($arPrice);

        $this->item = [
            'PRODUCT_ID' => $ar_item['ID'],
            'NAME' => $ar_item['NAME'],
            "LID" => LANG,
            'PRICE' => $arPrice['RESULT_PRICE']['DISCOUNT_PRICE'],
            'CURRENCY' => $arPrice['RESULT_PRICE']['CURRENCY'],
            'QUANTITY' => $q,
            'DETAIL_PAGE_URL' => "/catalog/".$ar_item['ID'],
            'CAN_BUY' => 'Y',
            'VAT_RATE' => $arPrice['RESULT_PRICE']['VAT_RATE'],
            'VAT_INCLUDED' => $arPrice['RESULT_PRICE']['VAT_INCLUDED'],
            'PRODUCT_PROVIDER_CLASS' => 'CCatalogProductProvider',
        ];
        return $this->item;
    }
    function itemSave(){
        if($this->item['PRODUCT_ID']){
            Debug::writeToFile("Сохраняем ");
            $basketItem = $this->basket->createItem("catalog", $this->item['PRODUCT_ID']);
            $basketItem->setFields($this->item);
            // обновление корзины
            $refreshStrategy = \Bitrix\Sale\Basket\RefreshFactory::create(\Bitrix\Sale\Basket\RefreshFactory::TYPE_FULL);
            $this->basket->refresh($refreshStrategy);
            $this->basket->save();
            return true;
        }
        Debug::writeToFile("Ошибка Нет PRODUCT_ID ".print_r($this->item,true));
        return false;
    }
}