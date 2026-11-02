<?php

namespace App\Console\Commands;

use App\Services\MpesaStkService;
use Illuminate\Console\Command;

/**
 * One-shot: register Safaricom C2B Confirmation/Validation URLs for the Paybill.
 * Without this, customers can pay account MARA but MARA never sees the callback.
 */
class RegisterMpesaC2bUrls extends Command
{
    protected $signature = 'mpesa:register-c2b-urls';

    protected $description = 'Register Daraja C2B Confirmation/Validation URLs for direct Paybill capture';

    public function handle(MpesaStkService $stk): int
    {
        try {
            $result = $stk->registerC2bUrls();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info($result['response_description'] ?? 'Registered');
        $this->line('Shortcode: '.$result['shortcode']);
        $this->line('Confirmation: '.$result['confirmation_url']);
        $this->line('Validation:   '.$result['validation_url']);

        return self::SUCCESS;
    }
}
