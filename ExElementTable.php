<?php

namespace SGM\Bitrix\Iblock;


\Bitrix\Main\Loader::includeModule('iblock');

use \Bitrix\Iblock\ElementTable;
use \Bitrix\Iblock\PropertyTable;
use \Bitrix\Iblock\PropertyEnumerationTable;
use CFile;
use CIBlock;
use CIBlockElement;
use Exception;

/**
 * Class ExElementTable
 * Adds more functionality for Bitrix ElementTable Class
 * @package SGM\Bitrix
 */
class ExElementTable extends ElementTable
{
    /**
     * Array of default Iblock element's fields
     * Fields are taken from :getMap() array
     * @var string[]
     */
    private static $arDefaultFields = array (
        "ID", "TIMESTAMP_X", "MODIFIED_BY", "DATE_CREATE", "CREATED_BY",
        "IBLOCK_ID", "IBLOCK_SECTION_ID", "ACTIVE", "ACTIVE_FROM", "ACTIVE_TO",
        "SORT", "NAME", "PREVIEW_PICTURE", "PREVIEW_TEXT", "PREVIEW_TEXT_TYPE",
        "DETAIL_PICTURE", "DETAIL_TEXT", "DETAIL_TEXT_TYPE", "SEARCHABLE_CONTENT",
        "WF_STATUS_ID", "WF_PARENT_ELEMENT_ID", "WF_NEW", "WF_LOCKED_BY", "WF_DATE_LOCK", "WF_COMMENTS",
        "IN_SECTIONS", "XML_ID", "CODE", "TAGS", "TMP_ID", "SHOW_COUNTER", "SHOW_COUNTER_START",
        "IBLOCK", "WF_PARENT_ELEMENT", "IBLOCK_SECTION", "MODIFIED_BY_USER", "CREATED_BY_USER", "WF_LOCKED_BY_USER",
    );

    /**
     * Метод обрабатывающий по ссылке параметр фильтрации перед ORM запросом
     * Позволяет фильтровать по ACTIVE_DATE=Y
     * @param array $arFilter parameters['filter']
     * @return array массив с дополнительными (не дефолтными) полями
     */
    private static function filterHandler (array &$arFilter): array
    {
        $arAdditional = array (); //Для поддержки одинаковой работы с selectHandler

        // Active date support for active by date filtering
        if ($arFilter['ACTIVE_DATE'] == 'Y') {
            $arAdditional['ACTIVE_DATE'] = $arFilter['ACTIVE_DATE'];
            unset($arFilter['ACTIVE_DATE']);
            $arFilter[] = array (
                'LOGIC' => 'AND',
                array (
                    'LOGIC' => 'OR',
                    '>=ACTIVE_TO' => new \Bitrix\Main\Type\DateTime(),
                    'ACTIVE_TO' => NULL,
                ),
                array (
                    'LOGIC' => 'OR',
                    '<=ACTIVE_FROM' => new \Bitrix\Main\Type\DateTime(),
                    'ACTIVE_FROM' => NULL,
                ),
            );
        }


        return $arAdditional;
    }


    /**
     * Метод по ссылке обрабатывающий параметр выборки перед ORM запросом
     * Позволяет выбрать SEO и свойства.
     * Свойствами считаются любые поля кроме дефолтных и SEO
     * @param array $arSelect parameters['select']
     * @return array массив с дополнительными (не дефолтными) полями
     */
    private static function selectHandler (array &$arSelect): array
    {
        $arAdditional = array ();
        $arToSelect = array ();

        foreach ($arSelect as $key => $field) {
            if (in_array($field, self::$arDefaultFields)) continue;


            if ($field === 'SEO') {
                $arAdditional['SEO'] = 'SEO';
            } else if ($field === 'DETAIL_PAGE_URL') {
                $arToSelect['DETAIL_PAGE_URL'] = 'IBLOCK.DETAIL_PAGE_URL';
            } else if (preg_match('/^(.*?)_(\d.*?)x(\d.*?)$/', $field, $matches)) {
                $arAdditional['RESIZED'][$matches[1]][$matches[2] . 'x' . $matches[3]] = array (
                    'width' => $matches[2],
                    'height' => $matches[3]
                );
            } else {
                $arAdditional['PROPERTIES'][] = $field;
            }

            unset($arSelect[$key]);
        }

        $arSelect = array_merge($arSelect, $arToSelect);

        return $arAdditional;
    }


    /**
     * @param array $arFields It is array of elements default fields
     * @param array $arAdditional Additional, not default fields like SEO
     * @return array new arFields with seo, formatted dates etc.
     */
    private static function resultHandler (array $arFields, array $arAdditional = array()): array
    {
        // Default
        if ($arFields["DETAIL_PICTURE"]) $arFields["DETAIL_PICTURE_PATH"] = CFile::GetPath($arFields["DETAIL_PICTURE"]);
        if ($arFields["PREVIEW_PICTURE"]) $arFields["PREVIEW_PICTURE_PATH"] = CFile::GetPath($arFields["PREVIEW_PICTURE"]);
        if ($arFields["ACTIVE_FROM"]) {
            $arFields["ACTIVE_FROM_FORMATTED"] = FormatDate("d F Y", $arFields["ACTIVE_FROM"]->getTimestamp());
            $arFields["ACTIVE_FROM"] = $arFields["ACTIVE_FROM"]->toString();
        }
        if ($arFields["ACTIVE_TO"]) {
            $arFields["ACTIVE_TO_FORMATTED"] = FormatDate("d F Y", $arFields["ACTIVE_TO"]->getTimestamp());
            $arFields["ACTIVE_TO"] = $arFields["ACTIVE_TO"]->toString();
        }
        if ($arFields["DATE_CREATE"]) {
            $arFields["DATE_CREATE_FORMATTED"] = FormatDate("d F Y", $arFields["DATE_CREATE"]->getTimestamp());
            $arFields["DATE_CREATE"] = $arFields["DATE_CREATE"]->toString();
        }
        if ($arFields["TIMESTAMP_X"]) {
            $arFields["TIME_UPDATE"] = $arFields["TIMESTAMP_X"]->getTimestamp();
            $arFields["DATE_UPDATE_FORMATTED"] = FormatDate("d F Y", $arFields["TIME_UPDATE"]);
            $arFields["DATE_UPDATE"] = $arFields["TIMESTAMP_X"]->toString();
            unset($arFields["TIMESTAMP_X"]);
        }
        if ($arFields['DETAIL_PAGE_URL']) {
            $arFields['DETAIL_PAGE_URL'] = CIBlock::ReplaceDetailUrl($arFields['DETAIL_PAGE_URL'], $arFields, false, 'E');
        }

        // Additional
        if ($arAdditional && $arFields['IBLOCK_ID'] && $arFields['ID']) {

            // == RESIZE ==
            foreach ($arAdditional['RESIZED'] as $resizedField => $sizes) {
                foreach ($sizes as $sizeName => $arSize) {
                    $fieldName = $resizedField . '_' . $sizeName;
                    $arFields[$fieldName] = CFile::ResizeImageGet($arFields[$resizedField], $arSize);
                    if ($arFields[$fieldName]) $arFields[$fieldName] = $arFields[$fieldName]['src'];
                }
            }

            // == SEO ==
            if ($arAdditional['SEO']) {
                $arFields['SEO'] = NULL;
                // Происходит выборка "наследуемых свойств", кажется сейчас это только SEO данные, но кто знает, что будет завтра
                $iPropValues = new \Bitrix\Iblock\InheritedProperty\ElementValues($arFields['IBLOCK_ID'], $arFields['ID']);
                $iPropValues = $iPropValues->getValues();
                if ($iPropValues['ELEMENT_META_TITLE']) $arFields['SEO']['TITLE'] = $iPropValues['ELEMENT_META_TITLE'];
                if ($iPropValues['ELEMENT_META_DESCRIPTION']) $arFields['SEO']['DESCRIPTION'] = $iPropValues['ELEMENT_META_DESCRIPTION'];
                if ($iPropValues['ELEMENT_META_KEYWORDS']) $arFields['SEO']['KEYWORDS'] = $iPropValues['ELEMENT_META_KEYWORDS'];
            }
        }

        return $arFields;
    }


    /**
     * Gets values for properties and adds it to array of elements
     * @param array $result referenced getArray method result
     * @param array $arElemsFilter
     * @param array $arPropertiesCodes
     * @param array $arLinkedPropertiesParams params (select, order) for getting referenced properties
     * @throws Exception
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     */
    public static function addProperties (array &$result, array $arElemsFilter, array $arPropertiesCodes, array $arLinkedPropertiesParams = array())
    {
        $propertyIDs = array ();
        $arProperties = array ();
        $enumProperties = array ();

        // Получение свойств
        $propertyIterator = PropertyTable::getList(array(
            'select' => array('*'),
            'filter' => array ('IBLOCK_ID' => $arElemsFilter['IBLOCK_ID'], 'CODE' => $arPropertiesCodes, 'ACTIVE' => 'Y'),
            'order' => array('SORT'=>'ASC', 'ID'=>'ASC')
        ));
        while ($property = $propertyIterator->fetch())
        {
            $arProperties[$property['ID']] = $property; //для получения значений
            if ($property['CODE']) $arProperties[$property['CODE']] = &$arProperties[$property['ID']]; //для получения привязанных элементов
            $propertyIDs[] = $property['ID'];
            if ($property['PROPERTY_TYPE'] === 'L') $enumProperties[] = $property['ID'];
        }

        if (empty($arProperties)) Throw new Exception("Property not exists. Property codes: " . implode(", ", $arPropertiesCodes));


        // Получение возможных значений свойств типа "список"
        $arEnums = array ();
        if ($enumProperties) {
            $enumIterator = PropertyEnumerationTable::getList(array(
                'select' => array('ID', 'VALUE', 'SORT', 'XML_ID'),
                'filter' => array('PROPERTY_ID' => $enumProperties)
            ));
            while ($enum = $enumIterator->fetch()) {
                $arEnums[$enum['ID']] = $enum;
            }
        }


        // Получение значений свойств
        $arPropValues = array ();
        $arLinkedPropertiesValues = array ();
        $propertyRes = (
        !empty($propertyIDs)
            ? CIBlockElement::GetPropertyValues($arElemsFilter['IBLOCK_ID'], $arElemsFilter, false, array('ID' => $propertyIDs))
            : CIBlockElement::GetPropertyValues($arElemsFilter['IBLOCK_ID'], $arElemsFilter, false)
        );
        while ($values = $propertyRes->Fetch()) {
            // Перебираем, преобразуем и промежуточно сохраняем все значения
            foreach ($values as $propID => $value) {
                if (is_numeric($propID)) {
                    // Получаем реальное значение для свойств типа "список"
                    if ($arProperties[$propID]['PROPERTY_TYPE'] === 'L' && $arEnums) {
                        if ($arProperties[$propID]['MULTIPLE'] === 'Y') {
                            foreach ($value as &$val) {
                                $val = $arEnums[$val]['VALUE'];
                                if (!$val) $val = NULL;
                            }
                        } else {
                            $value = $arEnums[$value]['VALUE'];
                            if (!$value) $value = NULL;
                        }
                    }

                    // Сохраняем в массив свойств по элементам
                    $key = $arProperties[$propID]['CODE'] ?: $propID;
                    $arPropValues[$values['IBLOCK_ELEMENT_ID']][$key] = $value ?: NULL;

                    // Дополнительно сохраняем все значения свойств типа "привязка к элементам"
                    if ($arProperties[$propID]['PROPERTY_TYPE'] === 'E' && !empty($arLinkedPropertiesParams)) {
                        if ($arProperties[$propID]['MULTIPLE'] === 'Y') {
                            foreach ($value as $val) {
                                if ($val) $arLinkedPropertiesValues[$key][] = $val;
                            }
                        } else {
                            if ($value) $arLinkedPropertiesValues[$key][] = $value;
                        }
                    }

                    // TODO: add property's description if needed
                }
            }

        }


        // Получаем значения свойств типа "привязка к элементам"
        $arLinkedValues = array ();
        if (!empty($arLinkedPropertiesValues)) {
            foreach ($arLinkedPropertiesParams as $code => $params) {
                if ($arLinkedPropertiesValues[$code]) {
                    $realLinkedParameters = array (
                        'select' => $params['select'] ?? array ('*'),
                        'order' => $params['order'] ?? array ('SORT' => 'ASC', 'ID' => 'DESC'),
                        'filter' => array (
                            'IBLOCK_ID' => $arProperties[$code]['LINK_IBLOCK_ID'],
                            'ID' => $arLinkedPropertiesValues[$code]
                        ),
                        // TODO: add more parameters if needed
                    );
                    $arLinkedValues[$code] = self::getArray($realLinkedParameters, "", $arLinkedPropertiesParams, 'ID');
                }
            }
        }


        // Добавление свойств к элементам и добавление связанных элементов
        foreach ($result as &$arItem) {
            if (!empty($arLinkedValues)) {
                foreach ($arPropValues[$arItem['ID']] as $propCode => &$value) {
                    if ($arProperties[$propCode]['PROPERTY_TYPE'] === 'E') {
                        if ($arProperties[$propCode]['MULTIPLE'] === 'Y') {
                            foreach ($value as &$val) {
                                if ($val) $val = $arLinkedValues[$propCode][$val];
                            }
                        } else {
                            if ($value) $value = $arLinkedValues[$propCode][$value];
                        }
                    }
                }
            }

            $arItem = array_merge($arItem, $arPropValues[$arItem['ID']]);
        }
    }

    /**
     * Extended getList
     * @param array $parameters
     * @return object
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     */
    public static function exGetList (array $parameters = array ()): object
    {
        if ($parameters['filter']) self::filterHandler($parameters['filter']);
        if ($parameters['select']) self::selectHandler($parameters['select']);
        return parent::getList($parameters);
    }


    /**
     * It is getList that returns array, gets properties and some additional fields
     * Can filter by some fields that is not available in D7 such as 'ACTIVE_DATE'
     * To filter by properties u need to pass apiCode
     *
     * @param array $parameters same as for getList
     * @param string $apiCode apiCode of iblock
     * @param array $arLinkedPropertiesParams params (select, order) for getting referenced properties
     * @param string $keyCode name of field that has to be key in result array (CODE, ID). If it is empty, then increment will be a key
     * @return array
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     */
    public static function getArray (array $parameters, string $apiCode = "", array $arLinkedPropertiesParams = array(), string $keyCode = ''): array
    {
        $arRes = array ();

        //Преобразование параметров
        if ($parameters['filter']) self::filterHandler($parameters['filter']);
        if ($parameters['select']) $arAdditional = self::selectHandler($parameters['select']);

        // Запрос
        if ($apiCode) {
            $className = "\\Bitrix\\Iblock\\Elements\\Element" . $apiCode . "Table";
            $result = $className::getList($parameters);
        } else {
            $result = parent::getList($parameters);
        }

        //Обработка запроса
        while ($arFields = $result->fetch()) {
            $arFields = self::resultHandler($arFields, $arAdditional);
            if ($arFields[$keyCode]) {
                $arRes[$arFields[$keyCode]] = $arFields;
            } else {
                $arRes[] = $arFields;
            }

            $arIDs[] = $arFields['ID']; //Для свойств
        }

        // Получение свойств для всех элементов
        if ($parameters['filter']['IBLOCK_ID'] && $arAdditional["PROPERTIES"] && $arIDs) {
            $arElemsFilter = array ('IBLOCK_ID' => $parameters['filter']['IBLOCK_ID'], 'ID' => $arIDs);
            if ($arAdditional["PROPERTIES"]) self::addProperties($arRes, $arElemsFilter, $arAdditional["PROPERTIES"], $arLinkedPropertiesParams);
        }


        return $arRes;
    }

    /**
     * Counts the total number of elements that satisfy (match) the filter
     * @param array $filter
     * @return false|mixed
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     */
    public static function countTotal (array $filter, string $apiCode = '')
    {
        self::filterHandler($filter);

        $parameters = array (
            'filter' => $filter,
            'group' => array ('COUNT' => 'IBLOCK_ID'),
            'select' => array ('CNT'),
            'runtime' => array (
                new \Bitrix\Main\Entity\ExpressionField('CNT', 'COUNT(*)')
            )
        );

        if ($apiCode) {
            $className = "\\Bitrix\\Iblock\\Elements\\Element" . $apiCode . "Table";
            $result = $className::getList($parameters);
        } else {
            $result = parent::getList($parameters);
        }
        if ($counter = $result->fetch()) {
            return $counter['CNT'];
        } else {
            return false;
        }
    }

    /**
     * @param array $arFields fields to save in add, update methods
     * @return array of additional fields (properties)
     */
    private static function saveFieldsHandler (array &$arFields): array
    {
        $arAdditional = array_diff_key($arFields, self::$arDefaultFields);
        $arFields = array_diff_key($arFields, $arAdditional);
        $arFields["PROPERTY_VALUES"] = (is_array($arAdditional["PROPERTY_VALUES"])) ? $arAdditional["PROPERTY_VALUES"] + $arAdditional : $arAdditional;
        return $arAdditional;
    }

    /**
     * @param mixed $id Element id
     * @return bool True for success and false for error. If u will try to delete element that is not exists then it returns true anyway
     * @todo Check if properties are deleting, check and add events
     */
    public static function delete ($id): bool
    {
        return CIBlockElement::Delete($id);
    }


    /**
     * @param array $arFields Array must contain IBLOCK_ID
     * @return mixed ID of new element
     * @throws Exception On error
     * @todo add SEO, improve files insert, check and add events
     */
    public static function add (array $arFields)
    {
        self::saveFieldsHandler($arFields);
        $el = new CIBlockElement();
        $res = $el->Add($arFields, false, false, true);
        if (!$res) Throw new Exception($el->LAST_ERROR);
        return $res;
    }

    /**
     * @param mixed $id Element ID
     * @param array $arFields You can not change IBLOCK_ID or ID. Properties are all not defaults fields
     * @return bool True for success
     * @throws Exception On error
     * @todo add SEO, improve files insert, do not reset properties that isn't passed in array, check and add events
     */
    public static function update ($id, array $arFields)
    {
        self::saveFieldsHandler($arFields);
        $el = new CIBlockElement();
        $res = $el->Update($id, $arFields, false, false, true, true);
        if (!$res) Throw new Exception($el->LAST_ERROR);
        return $res;
    }
}