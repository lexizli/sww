<?php   

/*<!--
Товары, входящих в заказы,

интернет-магазин sww.com.ru и его витрины

Подготовка файла синхронизации для витрины sww (версия 4.0).
Разработал Алексей Литвинов.
Версия 4.1
Начало разработки — 24.11.2022

По центральному складу товары в заказах, текущие остатки, остатки склада делим на два файла, так как есть кривые артикулы, начинающиеся на букву.

!
!              В магазине не должно быть товаров без кода артикула
!

*/

// На всякий случай посчитаем время выполнения скрипта

$startScriptTime = microtime(true); //начало работы скрипта
$currentTime = date("Y-m-d_H-i-s");

$orderedGoods = array();

$mysqli = new mysqli("localhost", "root", "DBlexizli@", "swwcomru") or die("Не удалось подключиться к MySQL");
if ($mysqli->connect_errno) {
    echo "Не удалось подключиться к MySQL: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error;
}

// удаляем все файлы в рабочем каталоге

array_map('unlink', glob("./work_files/*.csv"));

// Получаем товары в заказах с учетом склада, с которого они заказаны

$res=$mysqli->query("SELECT 
cscart_ecl_warehouse_product_amount.order_id,
cscart_ecl_warehouse_product_amount.product_id,
cscart_order_details.product_code,
cscart_ecl_warehouse_product_amount.amount, 
cscart_ecl_warehouse_product_amount.warehouse_id
FROM 
cscart_ecl_warehouse_product_amount 
LEFT JOIN cscart_orders ON cscart_ecl_warehouse_product_amount.order_id = cscart_orders.order_id 
LEFT JOIN cscart_order_details ON cscart_ecl_warehouse_product_amount.order_id = cscart_order_details.order_id
WHERE 
(cscart_orders.status = 'O' or cscart_orders.status = 'P')  
AND (cscart_order_details.product_id = cscart_ecl_warehouse_product_amount.product_id AND cscart_order_details.order_id = cscart_ecl_warehouse_product_amount.order_id)  
ORDER BY CAST(cscart_order_details.product_code AS UNSIGNED), cscart_order_details.product_code ASC");

$pattern = '/^\d/';   // для поиска артикулов, начинающихся с цифры
$res->data_seek(0);
$stockDepartments = [];  // номера складов

while ($row = $res->fetch_assoc()) {
    $orderedGoods[]= $row['warehouse_id'].';'.$row['order_id'].';'.$row['product_id'].';'.$row['product_code'].';'.$row['amount']."\n";
    $stockDepartments[] = $row['warehouse_id'];
}

$stockDepartments = array_unique($stockDepartments);  // выбираем уникальные номера ПВЗ/магазинов
$stockAmount = count($stockDepartments);          // считаем ПВЗ/магазины
$orderGoods = array();                         // массив для строк товаров для резервного файла
$goods = array();                               // массив для строк товаров для рабочего файла
$goodsSum = array();                           // массив для строк просуммированных по коду товаров для рабочего файла
$fileNames = array();                          // массив для имён файлов с товарами, заказанными из разных ПВЗ/магазинов
$patternSKU = 'яяяя';                           // используем для замены дефиса в артикулах, чтоб обеспечить верную сортировку   
$replaceSKU = '-';                              // тот самый дефис, который мы ищем и заменяем, а после сортировки снова возвращаем
$patternPregSKU = '/яяяя/';                     // используем в preg для замены дефиса в артикулах, чтоб обеспечить верную сортировку   
$replacePregSKU = '-';                          // тот самый дефис, который мы ищем и заменяем в preg, а после сортировки снова возвращаем

array_multisort($orderedGoods, SORT_ASC, SORT_STRING);  //сортируем массив по номерам ПВЗ/магазинов 
array_multisort($stockDepartments, SORT_ASC, SORT_STRING);  //сортируем массив ПВЗ/магазинов 

foreach ($stockDepartments as $stockString) {
    $fileNames[] = './work_files/'.$stockString.'Ordered'.'.csv'; 
//    echo "<br> line 79 $stockString";
}

//foreach ($orderedGoods as $debugString) {
//        echo "<br> line 83 $debugString";
//}

$departCounter = 0; 
         
foreach ($orderedGoods as $instring) {     //для каждой строки файла с  товарами заказа
    $strParts = explode(";",$instring);     // разбиваем строку по точке с запятой
    if ($strParts[0] == $stockDepartments[$departCounter]) {
        $orderGoods[] =  $instring;
    } else {
// сначала формируем и выводим файл
        array_unshift($orderGoods,"Warehouse;Order;Product ID;Product code;Amount\n");   //   вставляем шапку файла для импорта
        file_put_contents($fileNames[$departCounter], $orderGoods);
// затем обнуляем массив и увеличиваем счетчик складов        
        $orderGoods = array(); 
        $departCounter++;
        $orderGoods[] =  $instring;
    }
}

// выводим данные по последнему складу

array_unshift($orderGoods,"Warehouse;Order;Product ID;Product code;Amount\n");   //   вставляем шапку файла для импорта
file_put_contents($fileNames[$departCounter], $orderGoods);

/*

Суммируем количество одинаковых товаров в заказах

*/

foreach ($fileNames as $instring) {
    $summedGoods = array();        // Массив с суммами товаров в заказах
    $goodsSum = array();
    $buff = file($instring);        // читаем файл с товарами заказа и помещаем его в массив
    if ($buff) {

        array_splice($buff,0,1);  // удаляем первую строку

        foreach ($buff as $goodsInBuff) {
            $workGoodInBuff = explode(";",$goodsInBuff);
            $summedGoods[] = $workGoodInBuff[3].';'.$workGoodInBuff[4]."\n";
        }

        array_multisort($summedGoods, SORT_ASC, SORT_STRING);  //сортируем массив по id товаров 

    //  тут нужно просуммировать товары с одинаковым кодом  

        $goodId = "";
        $quantitySum = 0;
        $PCREpattern  =  '/\r\n|\r|\n/u';
         
        foreach ($summedGoods as $goodForSumm) {

                $goodForSumm = preg_replace($PCREpattern, '', $goodForSumm);
                $goodForSummParts = explode(";",$goodForSumm);

                if ( $goodForSummParts[0] === $goodId ) {

                    $quantitySum += $goodForSummParts[1];

                } else {

                    if ( $quantitySum >0 ) {
                        $goodsSumInOut = $goodId.';'.$quantitySum."\n";    // для рабочего файла
                        $goodsSum[] = $goodsSumInOut;
                    }

                    $goodId = $goodForSummParts[0];
                    $quantitySum = $goodForSummParts[1];
                }


        }   
        $goodsSumInOut = $goodId.';'.$quantitySum."\n";    // для рабочего файла
        $goodsSum[] = $goodsSumInOut;

        $goodsSum = str_replace($replaceSKU, $patternSKU, $goodsSum);
        array_multisort($goodsSum, SORT_ASC, SORT_NATURAL);  //сортируем массив по id товаров 
        $goodsSum = str_replace($patternSKU, $replaceSKU, $goodsSum);
            
        $instring = str_replace(".csv", "Sum.csv", $instring);
        file_put_contents($instring, $goodsSum);

    }    

}

/* 

В файлах, с именами типа depart14Sum.csv суммы заказанных товаров.

Дополнительно обрабатываем файл depart14Sum.csv — это товары с центрального склада

*/

$path = './work_files/14OrderedSum.csv';

$inputDepart14 = file($path);          // читаем файл с товарами заказа и помещаем его в массив

$depart14SumNum = array ();
$depart14SumLetter = array ();

foreach ($inputDepart14 as $wrkStock) {
    $wrkStockParts = explode(";",$wrkStock);
    if (preg_match($pattern,$wrkStockParts[0])) {
        $depart14SumNum[] = $wrkStockParts[0].";".$wrkStockParts[1];
    }
    else
    {
        $depart14SumLetter[] = $wrkStockParts[0].";".$wrkStockParts[1];
    }    
}

/*************************************/

$depart14SumNum = str_replace($replaceSKU, $patternSKU, $depart14SumNum);
$depart14SumLetter = str_replace($replaceSKU, $patternSKU, $depart14SumLetter);
array_multisort($depart14SumLetter, SORT_ASC, SORT_NATURAL);  //сортируем массив по id товаров 
array_multisort($depart14SumNum, SORT_ASC, SORT_NATURAL);  //сортируем массив по id товаров 
$depart14SumNum = str_replace($patternSKU, $replaceSKU, $depart14SumNum);
$depart14SumLetter = str_replace($patternSKU, $replaceSKU,  $depart14SumLetter);

/*************************************/

//foreach ($depart14SumNum as $counter) {
//    echo "<br> line 209 $counter";
//}

$outFilename = './work_files/14OrderedSumNum.csv';  //для контроля временно выводим в файл (рабочий вариант)
file_put_contents($outFilename, $depart14SumNum);

if ( count($depart14SumLetter) > 0 ) {
    $outFilename = './work_files/14OrderedSumLetter.csv';  //для контроля временно выводим в файл (рабочий вариант)
    file_put_contents($outFilename, $depart14SumLetter);
}

/*  готовы заказанные товары по складам */
// Получаем остатки интернет-магазина по складам
// центральный  — 14
// Москва       — 27

$res=$mysqli->query("SELECT cscart_product_descriptions.product,cscart_products.product_code,cscart_warehouses_products_amount.amount FROM cscart_warehouses_products_amount INNER JOIN cscart_products ON cscart_products.product_id = cscart_warehouses_products_amount.product_id INNER JOIN cscart_product_descriptions ON cscart_product_descriptions.product_id = cscart_warehouses_products_amount.product_id WHERE (cscart_warehouses_products_amount.warehouse_id = 14) ORDER BY cscart_product_descriptions.product, cscart_products.product_code ASC");
while ($row = $res->fetch_assoc()) {
    $stock14[] = $row['product'].';'.$row['product_code'].';'.$row['amount']."\n";
}

array_unshift($stock14,"Product name;Product_code;Amount\n");   //   вставляем шапку файла для импорта

$outFilename = './work_files/14CurrentAmount.csv';  //для контроля временно выводим в файл (рабочий вариант)

file_put_contents($outFilename, $stock14);


$res=$mysqli->query("SELECT cscart_product_descriptions.product,cscart_products.product_code,cscart_warehouses_products_amount.amount FROM cscart_warehouses_products_amount INNER JOIN cscart_products ON cscart_products.product_id = cscart_warehouses_products_amount.product_id INNER JOIN cscart_product_descriptions ON cscart_product_descriptions.product_id = cscart_warehouses_products_amount.product_id WHERE (cscart_warehouses_products_amount.warehouse_id = 27) ORDER BY cscart_product_descriptions.product, cscart_products.product_code ASC");

while ($row = $res->fetch_assoc()) {
    $stock27[] = $row['product'].';'.$row['product_code'].';'.$row['amount']."\n";
}

array_unshift($stock27,"Product name;Product_code;Amount\n");   //   вставляем шапку файла для импорта

$outFilename = './work_files/27CurrentAmount.csv';  //для контроля временно выводим в файл (рабочий вариант)

file_put_contents($outFilename, $stock27);

/* 

Обработка текущих остатков склада  — удаляем столбец с Product name

для центрального склада нужно сделать два файла остатков 

для артикулов, начинающихся с цифры: 14ClearCurrentAmountNum
для артикулов, начинающихся с буквы: 14ClearCurrentAmountLetter 

*/
array_splice($stock14,0,1);  // удаляем первую строку
array_splice($stock27,0,1);  // удаляем первую строку

$clearStock14Num = array ();
$clearStock14Letter = array ();

foreach ($stock14 as $wrkStock) {
    $stockParts = explode(";",$wrkStock);
    if (preg_match($pattern,$stockParts[1])) {
        $clearStock14Num[] = $stockParts[1].";".$stockParts[2];
    }
    else
    {
        $clearStock14Letter[] = $stockParts[1].";".$stockParts[2];
    }    
}

/*************************************/

$clearStock14Num = str_replace($replaceSKU, $patternSKU, $clearStock14Num);
$clearStock14Letter = str_replace($replaceSKU, $patternSKU, $clearStock14Letter);
array_multisort($clearStock14Letter, SORT_ASC, SORT_NATURAL);  //сортируем массив по id товаров 
array_multisort($clearStock14Num, SORT_ASC, SORT_NATURAL);  //сортируем массив по id товаров 
$clearStock14Num = str_replace($patternSKU, $replaceSKU, $clearStock14Num);
$clearStock14Letter = str_replace($patternSKU, $replaceSKU,  $clearStock14Letter);

/*************************************/

$outFilename = './work_files/14ClearCurrentAmountNum.csv';  //для контроля временно выводим в файл (рабочий вариант)
file_put_contents($outFilename, $clearStock14Num);
$outFilename = './work_files/14ClearCurrentAmountLetter.csv';  //для контроля временно выводим в файл (рабочий вариант)
file_put_contents($outFilename, $clearStock14Letter);

/* 
центральный склад был особый, т.к. там возможны артикулы, начинающиеся с буквы.
отрезаем первую строку и наименования товаров Product name , оставляя только Product_code и Amount для московского склада
*/

$clearStock27 = array (); //московский склад

foreach ($stock27 as $wrkStock) {
    $wrkStockParts = explode(";",$wrkStock);
    $clearStock27[] = $wrkStockParts[1].";".$wrkStockParts[2];
}

$clearStock27 = str_replace($replaceSKU, $patternSKU, $clearStock27);
array_multisort($clearStock27, SORT_ASC, SORT_NATURAL);  //сортируем массив по id товаров 
$clearStock27 = str_replace($patternSKU, $replaceSKU, $clearStock27);

$outFilename = './work_files/27ClearCurrentAmount.csv';  //для контроля временно выводим в файл (рабочий вариант)
file_put_contents($outFilename, $clearStock27);

/* готовим остатки склада, поступившие из 1С */
// Файл с заменами

$path = 'ftp://lexizli:swwlexizli!@77.222.54.50/html/sww.com.ru/stock/stock_product_names_to_clear.txt';
$changesNames = file($path); // читаем файл с наименованиями товаров — тех их частей, что нужно убрать из массива остатков склада

// центральный склад 

$path = 'ftp://lexizli:swwlexizli!@77.222.54.50/html/sww.com.ru/stock/stock_out.csv';

$inputSww = file($path);          // читаем файл с товарами заказа и помещаем его в массив

// замена, в соответствии с файлом stock_productNames_to_clear.txt      

foreach ($changesNames as $strWork) {  
    $strWork = trim($strWork).' ';
//    echo '<br>  |'.$strWork."|";
    $inputSww = str_replace($strWork, '', $inputSww);
}

/*
Дополнительно нужно убрать наименования складов

Склад готовой продукции -РЕЗЕРВ
Склад готовой продукции
Спецпредложение
ДИОНИКС
РАСПРОДАЖА
*/

$inputSww = str_replace(" (", '|', $inputSww);

/*  блок замены размеров и обратной замены кривых цветовых комбинаций */

$inputSww = str_replace("38/", '38_', $inputSww);
$inputSww = str_replace("40/", '40_', $inputSww);
$inputSww = str_replace("42/", '42_', $inputSww);
$inputSww = str_replace("44/", '44_', $inputSww);
$inputSww = str_replace("46/", '46_', $inputSww);
$inputSww = str_replace("48/", '48_', $inputSww);
$inputSww = str_replace("50/", '50_', $inputSww);
$inputSww = str_replace("52/", '52_', $inputSww);
$inputSww = str_replace("54/", '54_', $inputSww);
$inputSww = str_replace("56/", '56_', $inputSww);
$inputSww = str_replace("58/", '58_', $inputSww);
$inputSww = str_replace("60/", '60_', $inputSww);
$inputSww = str_replace("62/", '62_', $inputSww);
$inputSww = str_replace("64/", '64_', $inputSww);
$inputSww = str_replace("66/", '66_', $inputSww);
$inputSww = str_replace("68/", '68_', $inputSww);
$inputSww = str_replace("70/", '70_', $inputSww);
$inputSww = str_replace("74/", '74_', $inputSww);
$inputSww = str_replace("78/", '78_', $inputSww);
$inputSww = str_replace("82/", '82_', $inputSww);
$inputSww = str_replace("88-92/", '44-46_', $inputSww);
$inputSww = str_replace("96-100/", '48-50_', $inputSww);
$inputSww = str_replace("104-108/", '52-54_', $inputSww);
$inputSww = str_replace("112-116/", '56-58_', $inputSww);
$inputSww = str_replace("120-124/", '60-62_', $inputSww);
$inputSww = str_replace("128-132/", '64-66_', $inputSww);
$inputSww = str_replace("58_71", '58/71', $inputSww);
$inputSww = str_replace("58_58", '58/58', $inputSww);
$inputSww = str_replace("54_06", '54/06', $inputSww);


/* конец блока замены размеров  */

/* блок замены артикулов для долбанного Shell */

$inputSww = str_replace('SH1000', '117', $inputSww);
$inputSww = str_replace('SH1001', '118', $inputSww);
$inputSww = str_replace('SH1002', '115', $inputSww);
$inputSww = str_replace('SH1003', '116', $inputSww);
$inputSww = str_replace('SH1004', '119', $inputSww);
$inputSww = str_replace('SH1005', '111', $inputSww);
$inputSww = str_replace('SH1006', '112', $inputSww);
$inputSww = str_replace('SH1007', '113', $inputSww);
$inputSww = str_replace('SH1008', '120', $inputSww);
$inputSww = str_replace('SH1009', '122', $inputSww);
$inputSww = str_replace('SH1010', '121', $inputSww);
$inputSww = str_replace('SH1011', '114', $inputSww);
$inputSww = str_replace('SH1012', '212', $inputSww);
$inputSww = str_replace('SH1013', '211', $inputSww);
$inputSww = str_replace('SH1018', '213', $inputSww);
$inputSww = str_replace('SH1019', '214', $inputSww);
$inputSww = str_replace('SH1020', '215', $inputSww);
$inputSww = str_replace('SH1023', '302', $inputSww);


/* конец блока замены артикулов для долбанного Shell */

$inputSww = str_replace(")", '', $inputSww);

$inputSwwClearNum = array();
$inputSwwClearLetter = array();

// тут еще нужно разделить остатки склада на два массива: 
// первый — с буквами
// второй с кодами, начинающимися на цифру   ^[0-9]

foreach ($inputSww as $wrkStock) { 
    $wrkStockParts = explode(";",$wrkStock);
    if ( strpos($wrkStockParts[0], 'Склад') !== false ) { continue; }
    if ( strpos($wrkStockParts[0], 'ДИОНИК') !== false ) { continue; }
    if ( strpos($wrkStockParts[0], 'Спецпред') !== false ) { continue; }
    if ( strpos($wrkStockParts[0], 'РАСПРОД') !== false ) { continue; }


    if (preg_match($pattern,$wrkStockParts[0])) {
        $inputSwwClearNum[] = $wrkStockParts[0].";".$wrkStockParts[1]."\n";
    }
    else
    {
        $inputSwwClearLetter[] = $wrkStockParts[0].";".$wrkStockParts[1]."\n";
    }
}

/* и наконец, замена кирилических символов, если они вдруг есть */

$inputSwwClearNum = str_replace("Х", 'X', $inputSwwClearNum);
$inputSwwClearNum = str_replace("С", 'C', $inputSwwClearNum);
$inputSwwClearNum = str_replace("с", 'C', $inputSwwClearNum);
$inputSwwClearNum = str_replace("А", 'A', $inputSwwClearNum);
$inputSwwClearNum = str_replace("Н", 'H', $inputSwwClearNum);
$inputSwwClearNum = str_replace("Т", 'T', $inputSwwClearNum);
$inputSwwClearNum = str_replace("Р", 'P', $inputSwwClearNum);

$inputSwwClearLetter = str_replace("Х", 'X', $inputSwwClearLetter);
$inputSwwClearLetter = str_replace("С", 'C', $inputSwwClearLetter);
$inputSwwClearLetter = str_replace("с", 'C', $inputSwwClearLetter);
$inputSwwClearLetter = str_replace("А", 'A', $inputSwwClearLetter);
$inputSwwClearLetter = str_replace("Н", 'H', $inputSwwClearLetter);
$inputSwwClearLetter = str_replace("Т", 'T', $inputSwwClearLetter);
$inputSwwClearLetter = str_replace("Р", 'P', $inputSwwClearLetter);

$inputSwwClearNum = str_replace($replaceSKU, $patternSKU, $inputSwwClearNum);
$inputSwwClearLetter = str_replace($replaceSKU, $patternSKU, $inputSwwClearLetter);
array_multisort($inputSwwClearNum, SORT_ASC, SORT_NATURAL);  //сортируем массив по id товаров 
array_multisort($inputSwwClearLetter, SORT_ASC, SORT_NATURAL);  //сортируем массив по id товаров 
$inputSwwClearNum = str_replace($patternSKU, $replaceSKU, $inputSwwClearNum);
$inputSwwClearLetter = str_replace($patternSKU, $replaceSKU, $inputSwwClearLetter);

$outFilename = './work_files/inputSwwClearNum.csv';  //для контроля временно выводим в файл (рабочий вариант)
file_put_contents($outFilename, $inputSwwClearNum);

$outFilename = './work_files/inputSwwClearLetter.csv';  //для контроля временно выводим в файл (рабочий вариант)
file_put_contents($outFilename, $inputSwwClearLetter);


// московский ПВЗ 

$path = 'ftp://lexizli:swwlexizli!@77.222.54.50/html/sww.com.ru/stock/pvz_msk.csv';

$inputMsk = file($path);          // читаем файл с товарами заказа и помещаем его в массив

// замена, в соответствии с файлом stock_productNames_to_clear.txt      

foreach ($changesNames as $strWork) {  
    $strWork = trim($strWork).' ';
//    echo '<br>  |'.$strWork."|";
    $inputMsk = str_replace($strWork, '', $inputMsk);
}

/*
Дополнительно нужно убрать наименования склада

ПВЗ МОСКВА
*/

$inputMsk = str_replace(" (", '|', $inputMsk);

/*  блок замены размеров и обратной замены кривых цветовых комбинаций */

$inputMsk = str_replace("38/", '38_', $inputMsk);
$inputMsk = str_replace("40/", '40_', $inputMsk);
$inputMsk = str_replace("42/", '42_', $inputMsk);
$inputMsk = str_replace("44/", '44_', $inputMsk);
$inputMsk = str_replace("46/", '46_', $inputMsk);
$inputMsk = str_replace("48/", '48_', $inputMsk);
$inputMsk = str_replace("50/", '50_', $inputMsk);
$inputMsk = str_replace("52/", '52_', $inputMsk);
$inputMsk = str_replace("54/", '54_', $inputMsk);
$inputMsk = str_replace("56/", '56_', $inputMsk);
$inputMsk = str_replace("58/", '58_', $inputMsk);
$inputMsk = str_replace("60/", '60_', $inputMsk);
$inputMsk = str_replace("62/", '62_', $inputMsk);
$inputMsk = str_replace("64/", '64_', $inputMsk);
$inputMsk = str_replace("66/", '66_', $inputMsk);
$inputMsk = str_replace("68/", '68_', $inputMsk);
$inputMsk = str_replace("70/", '70_', $inputMsk);
$inputMsk = str_replace("74/", '74_', $inputMsk);
$inputMsk = str_replace("78/", '78_', $inputMsk);
$inputMsk = str_replace("82/", '82_', $inputMsk);
$inputMsk = str_replace("88-92/", '44-46_', $inputMsk);
$inputMsk = str_replace("96-100/", '48-50_', $inputMsk);
$inputMsk = str_replace("104-108/", '52-54_', $inputMsk);
$inputMsk = str_replace("112-116/", '56-58_', $inputMsk);
$inputMsk = str_replace("120-124/", '60-62_', $inputMsk);
$inputMsk = str_replace("128-132/", '64-66_', $inputMsk);
$inputMsk = str_replace("58_71", '58/71', $inputMsk);
$inputMsk = str_replace("58_58", '58/58', $inputMsk);
$inputMsk = str_replace("54_06", '54/06', $inputMsk);


/* конец блока замены размеров  */

$inputMsk = str_replace(")", '', $inputMsk);

$inputMskClearNum = array();

foreach ($inputMsk as $wrkStock) { 
    $wrkStockParts = explode(";",$wrkStock);
    if ( strpos($wrkStockParts[0], 'ПВЗ') !== false ) { continue; }
    $inputMskClearNum[] = $wrkStockParts[0].";".$wrkStockParts[1]."\n";
}

$inputMskClearNum = str_replace($replaceSKU, $patternSKU, $inputMskClearNum);
array_multisort($inputMskClearNum, SORT_ASC, SORT_NATURAL);  //сортируем массив по id товаров 
$inputMskClearNum = str_replace($patternSKU, $replaceSKU, $inputMskClearNum);

$outFilename = './work_files/inputMskClearNum.csv';  //для контроля временно выводим в файл (рабочий вариант)
file_put_contents($outFilename, $inputMskClearNum);


// макдоналдс (и почка) 

$path = 'ftp://lexizli:swwlexizli!@77.222.54.50/html/sww.com.ru/stock/mcdonalds.csv';

$inputMacdonalds = file($path);          // читаем файл с товарами заказа и помещаем его в массив

// замена, в соответствии с файлом stock_productNames_to_clear.txt      

foreach ($changesNames as $strWork) {  
    $strWork = trim($strWork).' ';
//    echo '<br>  |'.$strWork."|";
    $inputMacdonalds = str_replace($strWork, '', $inputMacdonalds);
}

/*
Дополнительно нужно убрать наименования склада

Склад McDonalds
*/

$inputMacdonalds = str_replace(" (", '|', $inputMacdonalds);
$inputMacdonalds = str_replace(")", '', $inputMacdonalds);

$inputMacdonaldsClearNum = array();

foreach ($inputMacdonalds as $wrkStock) { 
    $wrkStockParts = explode(";",$wrkStock);
    if ( strpos($wrkStockParts[0], 'McDonalds') !== false ) { continue; }
    $inputMacdonaldsClearNum[] = $wrkStockParts[0].";".$wrkStockParts[1]."\n";
}

$inputMacdonaldsClearNum = str_replace($replaceSKU, $patternSKU, $inputMacdonaldsClearNum);
array_multisort($inputMacdonaldsClearNum, SORT_ASC, SORT_NATURAL);  //сортируем массив по id товаров 
$inputMacdonaldsClearNum = str_replace($patternSKU, $replaceSKU, $inputMacdonaldsClearNum);

$outFilename = './work_files/inputMacdonaldsClearNum.csv';  //для контроля временно выводим в файл (рабочий вариант)
file_put_contents($outFilename, $inputMacdonaldsClearNum);

/*

что мы имеем в подготовленных файлах:

остатки в интернет-магазине

$14ClearCurrentAmountLetter — Остатки интернет-магазина на центральном складе (арткулы, начинающиеся с буквы)
$14ClearCurrentAmountNum    — Остатки интернет-магазина на центральном складе (арткулы, начинающиеся с цифры)
                                остатки товаров MacDonalds лежат на центральном складе и отличаются размерами!

$27ClearCurrentAmount       — Остатки интернет-магазина московского ПВЗ (тут нет на букву вообще)  

товары в заказах

$14OrderedSumLetter         — Товары центрального склада в заказах  (арткулы, начинающиеся с буквы) — этих почти всегда нет
$14OrderedSumNum            — Товары центрального склада в заказах  (арткулы, начинающиеся с цифры) — эти почти всегда есть

$27OrderedSum               — Товары ПВЗ Москва   — этих может не быть

Остатки складов в 1С        /выгруженных на сервер в каталог stock, но уже после подготовки/

$inputSwwClearLetter        — Остатки на центральном складе (арткулы, начинающиеся с буквы)
$inputSwwClearNum           — Остатки на центральном складе (арткулы, начинающиеся с цифры)
$inputMskClearNum           — Остатки московского ПВЗ
$inputMacdonaldsClearNum    — Остатки товаров для МакДоналдс

Сначала будем обрабатывать МакДоналдс
потом Москву
и наконец центральный склад

*/

$timeOfScript = microtime(true) - $startScriptTime; // время выполнения скрипта
printf('<br>Получены товары в заказах со статусами «Открыт» и «Обработан» за %.4F сек.', $timeOfScript);

// phpinfo();


?>
