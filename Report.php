<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use App\Http\Resources\MotivationResultsResource;

class Report extends Model
{
    const CHANNELS = [
        'general' => 'Общий',
        'diskPaints' => 'Покраска дисков',
        'seasonalStorage' => 'Сезонное хранение',
        'shifts' => 'Смены',
        'motivity' => 'Мотивити',
    ];

    protected $guarded = [];

    static $sendSeasonalStorageReport;

    public function path()
    {
        return "/backoffice/reports/{$this->id}";
    }

    public function recipients()
    {
        return $this->belongsToMany(User::class);
    }

    public function services()
    {
        return $this->belongsToMany(Service::class);
    }

    public function sendReport($workshop, $service)
    {
        $vars = [
            'service' => $service,
            'workshop' => $workshop,
        ];
        foreach($vars as $key => $value){
            $this->template = str_replace('%'.$key.'%', $value, $this->template);
        }
        $recipients = $this->recipients()->get()->pluck('email');
        self::sendReportToAmqp($this, $recipients, 'email', 'general');
        return app('amqp')->publish(json_encode(['message' => $this->template, 'recipients' => $recipients, 'type' => 'email', 'channel' => 'general']), 'message', [
            'exchange' => [
                'declare' => true,
                'type'    => 'direct',
                'name'    => 'direct.exchange',
            ],
        ]);
    }

    public static function sendOrderDeletedReport(Order $order, User $user)
    {
        $report = (new static)::where('sys_mark', 'OrderDeleted')->first();
        $vars = [
            'user' => $user->fullName,
            'order' => $order->id,
            'workshop' => $order->shift->workshop->address
        ];
        foreach($vars as $key => $value){
            $report->template = str_replace('%'.$key.'%', $value, $report->template);
        }
        $recipients = $report->recipients()->get()->pluck('email');
        self::sendReportToAmqp($report, $recipients, 'email', $report->channel);
    }

    public static function sendDiscountMoreThan25Report($order)
    {
        $report = (new static)::where('sys_mark', 'DiscountMoreThan25')->first();
        $report->template = str_replace('%order%', $order, $report->template);
        $recipients = $report->recipients()->get()->pluck('email');
        self::sendReportToAmqp($report, $recipients, 'email', $report->channel);
    }

    public static function sendDiskPaintPaidReport($order)
    {
        $report = (new static)::where('sys_mark', 'DiskPaintPaid')->first();
        $vars = $order->diskPaints->first()->getReportData();
        foreach($vars as $key => $value){
            $report->template = str_replace('%'.$key.'%', $value, $report->template);
        }
        $recipients = $report->recipients()->get()->pluck('email');
        self::sendReportToAmqp($report, $recipients, 'email', $report->channel);
    }

    public static function sendManualPriceReport($order)
    {
        $report = (new static)::where('sys_mark', 'ManualPrice')->first();
        $report->template = str_replace('%order%', $order, $report->template);
        $recipients = $report->recipients()->get()->pluck('email');
        self::sendReportToAmqp($report, $recipients, 'email', $report->channel);
    }

    public static function sendReportsFromArray($reports) {
        foreach($reports as $report) {
            switch ($report['report']) {
                case 'SeasonalStorageReport':
                    self::sendSeasonalStorageReport($report['order']);
                    break;
                case 'DiskPaintPaidReport':
                    self::sendDiskPaintPaidReport($report['order']);
                    break;
                case 'DiskPaintSohranReport':
                    self::sendDiskPaintSohranReport($report['order']);
                    break;
                case 'DiscountMoreThan25Report':
                    self::sendDiscountMoreThan25Report($report['order']);
                    break;
                case 'ManualPriceReport':
                    self::sendManualPriceReport($report['order']);
                    break;
            }
        }
    }

    public static function sendTooLongOpenedShiftReport($workshop)
    {
        $report = (new static)::where('sys_mark', 'tooLongOpenedShift')->first();
        $report->template = str_replace('%workshop%', $workshop, $report->template);
        $recipients = $report->recipients()->get()->pluck('email');
        self::sendReportToAmqp($report, $recipients, 'email', $report->channel);
    }

    private static function sendReportToAmqp($report, $recipients, $type, $channel)
    {
        if(!empty($recipients)) {
            return app('amqp')->publish(json_encode(['message' => $report->template, 'recipients' => $recipients, 'type' => $type, 'channel' => $channel]), 'message', [
                'exchange' => [
                    'declare' => true,
                    'type'    => 'direct',
                    'name'    => 'direct.exchange',
                ],
            ]);
        }
        return null;
    }
}
