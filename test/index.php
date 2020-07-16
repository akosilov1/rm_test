<?
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");
echo "<pre>";
Bitrix\Main\Loader::includeModule("sale");
Bitrix\Main\Loader::includeModule("iblock");

/*$arProps = \Bitrix\Iblock\PropertyTable::getList(array(
        'select' => array('*'),
        'filter' => array('IBLOCK_ID' => 37)
    ))->fetchAll();
print_r($arProps);*/
/**
 * OPERATION_TYPE
 *      MINUS_FROM_ORDER - Списание по заказу
 *      ADD_FROM_ORDER - Начисление по заказу
 * OPERATION_SUM - Сумма операции
 * ORDER_ID - Номер заказа
 * BALLANCE
 * BALLANCE_AFTER
 */
//$db_rez = $DB->Query("SELECT * FROM b_iblock_element_property WHERE IBLOCK_PROPERTY_ID = 651 AND VALUE = 28157");
//print_r($db_rez->Fetch());
print_r(
    $rez = CIBlockElement::GetList([],
        [
            'IBLOCK_ID' => 37,
            'PROPERTY_ORDER_ID' => 28157,
            'PROPERTY_OPERATION_TYPE_VALUE' => 'Списание по заказу'
        ],false,false,[
            'ID',
            'NAME',
            'IBLOCK_ID',
            'PROPERTY_OPERATION_SUM'
        ])->Fetch()
);
die("STOP");
$db_props = CIBlockElement::GetProperty($rez['IBLOCK_ID'],$rez['ID']);
while($ar_prop = $db_props->Fetch()){
    $ar_props[] = $ar_prop;
}
print_r($ar_props);
die("STOP");
//
$r = Bitrix\Sale\Order::Load(27481);
$basket = $r->getBasket();
/*print_r($basket);
die("STOP");*/
//echo "Basket ID:".$basket->get;
//$items = $basket->getBasketItems();
foreach ($basket as $item_){
    print_r($item = $item_->getFieldValues());
    $rez[] = [
        //'ID' => $item->getField['ID'],
        'NAME' => $item['NAME'],
        'QUANTITY' => $item['QUANTITY'],
        'PRICE' => $item['PRICE'],
        'VAT_RATE' => $item['VAT_RATE'],
        'VAT_INCLUDED' => $item['VAT_INCLUDED'],
    ];
}
print_r($rez);
die("STOP");
//App ID:
$app_id = "ffc1d1e1-63a2-4bd9-a458-5fccdc58f384";
//екретный ключ:
$app_key = "BN52Sj13ygo8ATJLl9QeUsiFadcxhzwI";
include_once __DIR__."/../local/php_interface/OpenApiConnector.php";
use OpenApiConnector as CONNECTOR;
echo "<pre>";
$connector = new CONNECTOR($app_id,$app_key); // Создание экземпляра класса

//print_r(json_decode( $connector->getSystemStatus(), true));
//$connector->openShift(); // Выполнение открытия смены
$billArray = [ // Массив с данными чека.
    "command" => [ // Массив с данными команды.
        "author" => "Михаил Ефремов", // (String) Имя кассира (Будет пробито на чеке).
        "smsEmail54FZ" => "+79201013053", // (String) Телефон или e-mail покупателя.
        "c_num" => 1111222333, // (int) Номер чека.
        "payed_cash" => 0.00, // (float) Сумма оплаты наличными (Не более 2-х знаков после точки).
        "payed_cashless" => 1500.10 , // (float) Сумма оплаты безаличным рассчетом (Не более 2-х знаков после точки).
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
echo json_encode($billArray, JSON_UNESCAPED_UNICODE);
//print_r($connector->printBill($billArray)); // Команда на печать чека прихода.
die("STOP");
//$_REQUEST['ORDER_ID'] = 25679;
$ORDER_ID = 27481;
//require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/sberbank.ecom/payment/payment.php");
\Bitrix\Main\Loader::includeModule("sale");

$r = Bitrix\Sale\Order::getList(array(
    'filter' => ['PAY_SYSTEM_ID' => 4, 'PAYED' => 'Y', 'STATUS_ID' => 'PP', '>=DATE_INSERT' => '13.12.2018'],
    'select' => ['EXTERNAL_ORDER', 'UPDATED_1C', 'ID', 'PAY_SYSTEM_ID', 'STATUS_ID', 'PAYED', 'DATE_PAYED'],
    'limit' => 5
));
while ($arOrder = $r->fetch())
{
    print_r($arOrder);
}
/*\Bitrix\Main\Loader::includeModule("sale");
$r = Bitrix\Sale\Order::Load($ORDER_ID);
print_r($r);*/

die("STOP");
$dbBasketItems = CSaleBasket::GetList(
        array(
                "NAME" => "ASC",
                "ID" => "ASC"
            ),
        array(
      //"FUSER_ID" => CSaleBasket::GetBasketUserID(),
                "LID" => SITE_ID,
                "ORDER_ID" => $ORDER_ID
            ),
        false,
        false,
        array("*")
    );
while ($arItems = $dbBasketItems->Fetch())
{

    $arBasketItems[] = $arItems;
}
echo "<pre>";
print_r($arBasketItems);
/*if(CSite::InGroup(rm_conf::OPT_USER_GROUPS)) print_r(rm_conf::OPT_USER_GROUPS);
$c= new CSite();
print_r($c);*/
?>
<?
//echo date("d.m.Y H:i:s");
?>
<?/*
$arOrder = CSaleOrder::GetByID(25679);

   echo "<pre>";
   print_r($arOrder);
   echo "</pre>";
*/
?>
<?
// Выведем актуальную корзину для текущего пользователя
/*
$arBasketItems = array();

$dbBasketItems = CSaleBasket::GetList(
        array(
                "NAME" => "ASC",
                "ID" => "ASC"
            ),
        array(
			//"FUSER_ID" => CSaleBasket::GetBasketUserID(),
                "LID" => SITE_ID,
                "ORDER_ID" => 25679
            ),
        false,
        false,
        array("ID", "CALLBACK_FUNC", "MODULE", 
              "PRODUCT_ID", "QUANTITY", "DELAY", 
              "CAN_BUY", "PRICE", "WEIGHT")
    );
while ($arItems = $dbBasketItems->Fetch())
{

    $arBasketItems[] = $arItems;
}

// Печатаем массив, содержащий актуальную на текущий момент корзину
echo "<pre>";
print_r($arBasketItems);
echo "</pre>";*/
?>