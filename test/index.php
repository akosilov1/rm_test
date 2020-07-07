<?
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
$APPLICATION->SetTitle("test");
?>
<pre>
<?
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

<?require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php");?>