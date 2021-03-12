<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Order;
use App\DiscountThreshold;
use App\LoyaltyDiscountCharge;

class ChargeLoyaltyAdditionalDiscountJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $order;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        if(!$this->order->user
        || !$this->order->user->hasClientType
        || !$this->order->user->discountCard
        || !$this->order->user->userable->charge_additional_discount
        ) {
            return 0;
        }

        $this->chargeLoyaltyDiscountPercent();
    }

    private function chargeLoyaltyDiscountPercent() {
        if($this->canChargeLoyaltyPercent()) {
            LoyaltyDiscountCharge::create([
                'amount' => DiscountThreshold::current(),
                'discount_added' => 1,
                'discount_card_id' => $this->order->user->discountCard->id
                ]);

            $this->order
            ->user
            ->discountCard
            ->chargeAdditionalLoyaltyPercent();

            $this->chargeLoyaltyDiscountPercent();
        }
    }

    private function canChargeLoyaltyPercent() {
        return intdiv($this->order->user->userable->spent() - LoyaltyDiscountCharge::lastCharges($this->order->user->discountCard), DiscountThreshold::current()) > 0 ? true : false;
    }
}
