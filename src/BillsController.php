<?php


class BillsController
{
    public function store(\App\Services\OfdApi $ofdApi) {
        try {
            $ofdApi->storeBill($request->get());
        } catch (ApiException $e) {
            json('Ошибка списания денег');
        }
    }
}


/*
 Микросервис Заказы
методы апи:
/create-order
/get-order
/delete-order

Микросервис Пользователи
/get-user
/update-user
...