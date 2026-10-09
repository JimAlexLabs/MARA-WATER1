<?php

namespace App\Console\Commands;

use App\Models\MpesaPayment;
use App\Services\MpesaStkService;
use Illuminate\Console\Command;

class ReconcileMpesaPayments extends Command
{
    protected $signature = 'mara:reconcile-mpesa';

    protected $description = 'Ask Safaricom or the payment gateway to settle STK payments still pending';

    public function handle(MpesaStkService $stk): int
    {
        $rows = MpesaPayment::where('status', 'pending')
            ->where('created_at', '<', now()->subMinutes(2))
            ->orderBy('created_at')
            ->limit(100)
            ->get();

        foreach ($rows as $payment) {
            $stk->refresh($payment);
        }

        $this->info('Checked '.$rows->count().' pending payments.');

        return self::SUCCESS;
    }
}
