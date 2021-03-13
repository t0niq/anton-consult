<?php


namespace App\Services;


class OfdApi
{
    public function __construct(HttpClient $client, string $token)
    {

    }

    public function getBill(int $billId)
    {
        $response = $this->request();

        if (isset($response['error'])) {
            throw new Exception();
        }

        return $result;


    }

    public function storeBill(array $bill)
    {

    }

    protected function request(string $url, array $data)
    {

    }
}