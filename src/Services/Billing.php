<?php


namespace App\Services;

ApiException => ApiBillingException => ApiBillingNotEnoughMoneyException


class Billing
{
    public function __construct(OfdApi $api)
    {
    }

    public function chargeCustomer(Customer $customer, int $sum) {

        $result = $ofdApi->call();
        if ($result['error']) {
            return $error;
        }

        try {
            $db->addPendingPayment();
            $paymentGateway->charge($customer);
            $db->setUserPaid($cutomer);
        } catch (ApiBillingNotEnoughMoneyException $e) {
            $db->setUserUnpaid($customer);
            $paymentGateway->refund();
            $db->removePendingPayment();

            throw $e;
        } catch (DbExcpetion $e) {

        }


    }
}