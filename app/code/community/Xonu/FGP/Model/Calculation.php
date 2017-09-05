<?php

/**
 * @copyright (c) 2013, Pawel Kazakow <support@xonu.de>
 * @license http://xonu.de/license/ xonu.de EULA
 */
class Xonu_FGP_Model_Calculation extends Mage_Tax_Model_Calculation
{
    protected $adminSession = false;

    /**
     * Set origin to destination
     *
     * Get request object for getting tax rate based on store shippig original address
     *
     * @param   null|store $store
     * @return  Varien_Object
     * @throws \Mage_Core_Model_Store_Exception
     */
    public function getRateOriginRequest($store = null)
    {
        if (!Mage::helper('xonu_fpg')->isCrossBorderEnabled($store)) {
            return parent::getRateOriginRequest($store);
        }

        if (Mage::getDesign()->getArea() === 'adminhtml' || Mage::app()->getStore()->isAdmin()) {
            $this->adminSession = true; // creating order in the backend
        }

        $session = Mage::helper('xonu_fpg')->getSession();

        if ($this->adminSession || $session->hasQuote()) { // getQuote() would lead to infinite loop here when switching currency
            $quote = $session->getQuote();

            if (($this->adminSession || $quote->getIsActive())
                && !Mage::helper('xonu_fpg')->isExcludedCountry($quote->getBillingAddress(),
                    $quote->getShippingAddress(), $store)
            ) {
                // use destination of the existing quote as origin if quote exists
                $request = $this->getRateRequest(
                    $quote->getShippingAddress(),
                    $quote->getBillingAddress(),
                    $quote->getCustomerTaxClassId(),
                    $store
                );

                return $request;
            }

            if (Mage::getSingleton('customer/session')->isLoggedIn()) {
                $customer = Mage::getSingleton('customer/session')->getCustomer();

                if (($billingAddress = $customer->getDefaultBillingAddress())
                    && ($shippingAddress = $customer->getDefaultShippingAddress())
                    && !Mage::helper('xonu_fpg')->isExcludedCountry($billingAddress, $shippingAddress, $store)
                ) {
                    // use customer addresses as origin if customer is logged in
                    $request = $this->getRateRequest(
                        $shippingAddress,
                        $billingAddress,
                        $customer->getTaxClassId(),
                        $store
                    );

                    return $request;
                }
            }
        }

        // quote is not available when switching the currency
        return $this->getDefaultDestination();
    }

    /**
     * Sometimes it is required to use shipping address from the quote instead of the default address
     *
     * Get request object with information necessary for getting tax rate
     * Request object contain:
     *  country_id (->getCountryId())
     *  region_id (->getRegionId())
     *  postcode (->getPostcode())
     *  customer_class_id (->getCustomerClassId())
     *  store (->getStore())
     *
     * @param   null|false|Varien_Object $shippingAddress
     * @param   null|false|Varien_Object $billingAddress
     * @param   null|int $customerTaxClass
     * @param   null|int $store
     * @return  Varien_Object
     */
    public function getRateRequest(
        $shippingAddress = null,
        $billingAddress = null,
        $customerTaxClass = null,
        $store = null
    ) {
        if (!Mage::helper('xonu_fpg')->isCrossBorderEnabled($store)) {
            return parent::getRateRequest($shippingAddress, $billingAddress, $customerTaxClass, $store);
        }

        if ($shippingAddress === false && $billingAddress === false && $customerTaxClass === false) {
            return $this->getRateOriginRequest($store);
        }

        $address = new Varien_Object();
        $customer = $this->getCustomer();
        $basedOn = Mage::getStoreConfig(Mage_Tax_Model_Config::CONFIG_XML_PATH_BASED_ON, $store);

        if (($shippingAddress === false && $basedOn === 'shipping') || ($billingAddress === false && $basedOn === 'billing')
        ) {
            $basedOn = 'default';
        } else {
            if (
                (($billingAddress === false || null === $billingAddress || !$billingAddress->getCountryId()) && $basedOn === 'billing')
                || (($shippingAddress === false || null === $shippingAddress || !$shippingAddress->getCountryId()) && $basedOn === 'shipping')
            ) {
                $session = Mage::getSingleton('checkout/session');
                if ($customer) {
                    $defBilling = $customer->getDefaultBillingAddress();
                    $defShipping = $customer->getDefaultShippingAddress();

                    if ($basedOn === 'billing' && $defBilling && $defBilling->getCountryId()) {
                        $billingAddress = $defBilling;
                    } else {
                        if ($basedOn === 'shipping' && $defShipping && $defShipping->getCountryId()) {
                            $shippingAddress = $defShipping;
                        } else {
                            if ($this->adminSession || $session->hasQuote()) {
                                $quote = $session->getQuote();
                                $isActive = $quote->getIsActive();
                                if ($isActive) {
                                    $shippingAddress = $quote->getShippingAddress();
                                    $billingAddress = $quote->getBillingAddress();
                                } else {
                                    $basedOn = 'default';
                                }
                            } else {
                                $basedOn = 'default';
                            }
                        }
                    }
                } else {
                    if ($this->adminSession || $session->hasQuote()) {
                        $quote = $session->getQuote();
                        $isActive = $quote->getIsActive();
                        if ($isActive) {
                            $shippingAddress = $quote->getShippingAddress();
                            $billingAddress = $quote->getBillingAddress();
                        } else {
                            $basedOn = 'default';
                        }
                    } else {
                        $basedOn = 'default';
                    }
                }
            }
        }

        switch ($basedOn) {
            case 'billing':
                $address = $billingAddress;
                break;
            case 'shipping':
                $address = $shippingAddress;
                break;
            case 'origin':
                $address = $this->getRateOriginRequest($store);
                break;
            case 'default':
                $address
                    ->setCountryId(Mage::getStoreConfig(Mage_Tax_Model_Config::CONFIG_XML_PATH_DEFAULT_COUNTRY, $store))
                    ->setRegionId(Mage::getStoreConfig(Mage_Tax_Model_Config::CONFIG_XML_PATH_DEFAULT_REGION, $store))
                    ->setPostcode(Mage::getStoreConfig(Mage_Tax_Model_Config::CONFIG_XML_PATH_DEFAULT_POSTCODE,
                        $store));
                break;
        }

        if (null === $customerTaxClass && $customer) {
            $customerTaxClass = (int)$customer->getTaxClassId();
        } elseif (($customerTaxClass === false) || !$customer) {
            $customerTaxClass = (int)$this->getDefaultCustomerTaxClass($store);
        }

        // fallback to the store-defaults, if the (anonymous) customer does not come with valid tax-references
        // (it would fall down to 0%-tax in the frontend display)
        if (null === $address->getCountryId()) {
            $address->setCountryId(Mage::getStoreConfig(Mage_Tax_Model_Config::CONFIG_XML_PATH_DEFAULT_COUNTRY,
                $store));
        }

        if (null === $address->getRegionId()) {
            $address->setRegionId(Mage::getStoreConfig(Mage_Tax_Model_Config::CONFIG_XML_PATH_DEFAULT_REGION, $store));
        }

        $request = new Varien_Object();
        $request
            ->setCountryId($address->getCountryId())
            ->setRegionId($address->getRegionId())
            ->setPostcode($address->getPostcode())
            ->setStore($store)
            ->setCustomerClassId($customerTaxClass);

        return $request;
    }

    /**
     * @param null $store
     * @return Varien_Object
     */
    public function getDefaultDestination($store = null)
    {
        $address = new Varien_Object();
        $request = new Varien_Object();

        $address
            ->setCountryId(Mage::getStoreConfig(Mage_Tax_Model_Config::CONFIG_XML_PATH_DEFAULT_COUNTRY, $store))
            ->setRegionId(Mage::getStoreConfig(Mage_Tax_Model_Config::CONFIG_XML_PATH_DEFAULT_REGION, $store))
            ->setPostcode(Mage::getStoreConfig(Mage_Tax_Model_Config::CONFIG_XML_PATH_DEFAULT_POSTCODE, $store));

        $customerTaxClass = null;
        $customer = $this->getCustomer();

        if (null === $customerTaxClass && $customer) {
            $customerTaxClass = $customer->getTaxClassId();
        } elseif (($customerTaxClass === false) || !$customer) {
            $customerTaxClass = $this->getDefaultCustomerTaxClass($store);
        }

        $request
            ->setCountryId($address->getCountryId())
            ->setRegionId($address->getRegionId())
            ->setPostcode($address->getPostcode())
            ->setStore($store)
            ->setCustomerClassId($customerTaxClass);

        return $request;
    }
}
