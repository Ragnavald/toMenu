<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Services\StripeMode;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Definição canônica dos planos.
     *
     * Vive aqui e é reusada pelo `TenantRegistrar`, que precisa garantir o
     * plano na hora do cadastro em ambientes onde o seed nunca rodou. Duplicar
     * esses números nos dois lugares já foi a origem de uma loja nascer com
     * capacidades diferentes das anunciadas na landing.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function definitions(): array
    {
        return [
            Plan::MENU_ONLY => [
                'name' => 'Cardápio digital',
                'price_cents' => 2900,
                'max_products' => 150,
                'allows_online_payment' => false,
                'allows_orders' => false,
                'allows_delivery' => false,
                'sort_order' => 1,
            ],
            Plan::PRO => [
                'name' => 'Pro',
                'price_cents' => 9900,
                'max_products' => 500,
                'allows_online_payment' => true,
                'allows_orders' => true,
                'allows_delivery' => true,
                'sort_order' => 2,
            ],
        ];
    }

    /**
     * Price id do plano no Stripe, conforme o modo ativo (test|live).
     *
     * Fica no ambiente, e não fixo aqui, porque o id difere entre os dois
     * ambientes do Stripe — o mesmo motivo das chaves serem dois conjuntos.
     * Ver `App\Services\StripeMode`.
     */
    public static function stripePriceId(string $slug): ?string
    {
        $mode = strtoupper(app(StripeMode::class)->current());
        $key = 'STRIPE_'.$mode.'_PRICE_'.strtoupper(str_replace('-', '_', $slug));

        return env($key) ?: null;
    }

    public function run(): void
    {
        foreach (self::definitions() as $slug => $attributes) {
            $priceId = self::stripePriceId($slug);

            /*
             * O price id só entra no update quando existe no ambiente.
             *
             * Este seed roda a cada deploy: incluir a chave sempre faria um
             * ambiente sem a variável apagar o id já configurado no banco, e a
             * assinatura pararia de funcionar sem que nada tivesse mudado no
             * código. Ausente = "não sei", e não "limpe".
             */
            if ($priceId !== null) {
                $attributes['stripe_price_id'] = $priceId;
            }

            // updateOrCreate e não firstOrCreate: o seed roda de novo a cada
            // `make up`, e um preço ou limite corrigido aqui precisa alcançar
            // um banco que já tem a linha.
            Plan::updateOrCreate(['slug' => $slug], $attributes);
        }
    }
}
