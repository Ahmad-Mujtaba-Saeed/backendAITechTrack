<?php

namespace Modules\Billing\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Billing\Models\Plan;
use Modules\Billing\Services\StripePlanService;

class PlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $plans = [
            [
                'name' => 'Weekly Plan',
                'price' => 5.00,
                'currency' => 'USD',
                'interval' => 'week',
                'interval_count' => 1,
                'subdesc' => 'This one is a weekly plan',
                'features' => ['Get Unlimited Access to our website'],
                'is_active' => true,
            ],
            [
                'name' => 'Monthly Plan',
                'price' => 15.00,
                'currency' => 'USD',
                'interval' => 'month',
                'interval_count' => 1,
                'subdesc' => 'This one is a Monthly plan',
                'features' => ['Get Unlimited Access to our website'],
                'is_active' => true,
            ],
            [
                'name' => 'Quarterly Plan',
                'price' => 10.00,
                'currency' => 'USD',
                'interval' => 'month',
                'interval_count' => 3,
                'subdesc' => 'This one is a Quarterly plan',
                'features' => ['Get Unlimited Access to our website'],
                'is_active' => true,
            ],
        ];

        $stripeService = app(StripePlanService::class);

        foreach ($plans as $planData) {
            try {
                // Create Stripe product and price
                $stripeData = $stripeService->createPlan([
                    'name' => $planData['name'],
                    'price' => $planData['price'],
                    'currency' => $planData['currency'],
                    'interval' => $planData['interval'],
                    'interval_count' => $planData['interval_count'],
                ]);

                // Create local plan record
                Plan::create([
                    'name' => $planData['name'],
                    'price' => $planData['price'],
                    'currency' => $planData['currency'],
                    'interval' => $planData['interval'],
                    'interval_count' => $planData['interval_count'],
                    'subdesc' => $planData['subdesc'],
                    'features' => $planData['features'],
                    'is_active' => $planData['is_active'],
                    'stripe_product_id' => $stripeData['product_id'] ?? null,
                    'stripe_price_id' => $stripeData['price_id'] ?? null,
                ]);

                $this->command->info("Created plan: {$planData['name']}");
            } catch (\Exception $e) {
                $this->command->error("Failed to create plan {$planData['name']}: " . $e->getMessage());
            }
        }
    }
}



