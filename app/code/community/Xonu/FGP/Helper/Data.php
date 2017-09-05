<?php
/**
 * This file is part of FGP for Magento.
 *
 * @package     FGP
 * @copyright   Copyright (c) 2017 Newtown-Web OG (http://www.newtown.at)
 * @author      Ingo Fabbri <if@newtown.at>
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

class Xonu_FGP_Helper_Data extends Mage_Core_Helper_Abstract
{
    const XML_PATH_TAX_CALCULATION_XONU_FPG_CROSS_BORDER_TRADE_ENABLED = 'tax/calculation/xonu_fpg_cross_border_trade_enabled';
    const XML_PATH_TAX_CALCULATION_XONU_FPG_CROSS_BORDER_TRADE_EXCLUDE_COUNTRIES = 'tax/calculation/xonu_fpg_cross_border_trade_exclude_countries';

    /**
     * @param $store
     * @return bool
     */
    public function isCrossBorderEnabled($store = null)
    {
        return Mage::getStoreConfigFlag(static::XML_PATH_TAX_CALCULATION_XONU_FPG_CROSS_BORDER_TRADE_ENABLED, $store);
    }

    /**
     * Check whether specified country is in EU countries list
     *
     * @param string $countryCode
     * @param null|int $storeId
     * @return bool
     */
    public function isExcludedCountry($billingAddress, $shippingAddress, $store = null)
    {
        $address = Mage::helper('xonu_fpg')->getAddressBasedOn($billingAddress, $shippingAddress, $store);

        $excludedCountries = explode(',',
            Mage::getStoreConfig(static::XML_PATH_TAX_CALCULATION_XONU_FPG_CROSS_BORDER_TRADE_EXCLUDE_COUNTRIES,
                $store)
        );

        return in_array($address->getCountryId(), $excludedCountries, true);
    }

    /**
     * @param null $billingAddress
     * @param null $shippingAddress
     * @param null $store
     * @return null
     */
    public function getAddressBasedOn($billingAddress = null, $shippingAddress = null, $store = null)
    {
        $basedOn = Mage::helper('tax')->getTaxBasedOn($store);

        switch ($basedOn) {
            case 'billing':
                $address = $billingAddress;
                break;
            case 'shipping':
                $address = $shippingAddress;
                break;
            case 'origin':
            default:
                $address = $this->getDefaultDestination();
        }

        return $address;
    }

    /**
     * @return Mage_Adminhtml_Model_Session_Quote|Mage_Checkout_Model_Session|Mage_Core_Model_Abstract
     */
    public function getSession()
    {
        if (Mage::getDesign()->getArea() === 'adminhtml' || Mage::app()->getStore()->isAdmin()) {
            return Mage::getSingleton('adminhtml/session_quote'); // order creation in the backend
        }

        return Mage::getSingleton('checkout/session'); // default order creation in the frontend
    }
}
