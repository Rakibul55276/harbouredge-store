<?php

namespace Database\Seeders;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Webkul\Category\Repositories\CategoryRepository;
use Webkul\Core\Repositories\ChannelRepository;
use Webkul\Core\Repositories\CoreConfigRepository;
use Webkul\Core\Repositories\CurrencyRepository;
use Webkul\Core\Repositories\LocaleRepository;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Tax\Repositories\TaxCategoryRepository;
use Webkul\Tax\Repositories\TaxRateRepository;

/**
 * Sets up the HarbourEdge store: Arabic + English, SAR, 15% KSA VAT, branding,
 * the PPE catalogue and the homepage. Safe to run more than once.
 *
 * php artisan db:seed --class=HarbourEdgeSeeder
 */
class HarbourEdgeSeeder extends Seeder
{
    /**
     * Folder holding catalog.json, product photos, icons, banners and brand files.
     */
    protected string $dataPath;

    /**
     * Category ids keyed by slug.
     *
     * @var array<string, int>
     */
    protected array $categoryIds = [];

    /**
     * Create a new seeder instance.
     */
    public function __construct(
        protected LocaleRepository $localeRepository,
        protected CurrencyRepository $currencyRepository,
        protected ChannelRepository $channelRepository,
        protected CoreConfigRepository $coreConfigRepository,
        protected TaxCategoryRepository $taxCategoryRepository,
        protected TaxRateRepository $taxRateRepository,
        protected CategoryRepository $categoryRepository,
        protected ProductRepository $productRepository,
    ) {
        $this->dataPath = database_path('data/harbouredge');
    }

    /**
     * Run the seeder. Laravel unguards models while seeding; the repositories rely on
     * $fillable to drop form-only keys, so guarding is switched back on here.
     */
    public function run(): void
    {
        $catalog = json_decode(file_get_contents($this->dataPath.'/catalog.json'), true);

        Model::reguard();

        try {
            $this->setupLocalesAndChannel();

            $taxCategoryId = $this->setupTax();

            $this->setupConfiguration();

            $this->setupCategories($catalog['categories']);

            $this->setupProducts($catalog['products'], $taxCategoryId);

            $this->setupHomepage();
        } finally {
            Model::unguard();
        }

        $this->command?->info('HarbourEdge store data is ready.');
    }

    /**
     * Add Arabic (RTL), make SAR the only currency and brand the default channel.
     */
    protected function setupLocalesAndChannel(): void
    {
        $english = $this->localeRepository->findOneByField('code', 'en');

        $arabic = $this->localeRepository->findOneByField('code', 'ar')
            ?? $this->localeRepository->create(['code' => 'ar', 'name' => 'العربية', 'direction' => 'rtl']);

        $sar = $this->currencyRepository->findOneByField('code', 'SAR')
            ?? $this->currencyRepository->create(['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => 'ر.س', 'decimal' => 2]);

        $channel = $this->channelRepository->findOneByField('code', 'default');

        $channel->update([
            'hostname' => config('app.url'),
            'default_locale_id' => $english->id,
            'base_currency_id' => $sar->id,
        ]);

        $channel->locales()->sync([$english->id, $arabic->id]);

        $channel->currencies()->sync([$sar->id]);

        $translations = [
            'en' => [
                'name' => 'HarbourEdge',
                'description' => 'Certified PPE and safety equipment supplier in Saudi Arabia.',
                'seo' => ['meta_title' => 'HarbourEdge | PPE & Safety Equipment in Saudi Arabia', 'meta_description' => 'Buy certified safety shoes, FR workwear, eye protection and site safety products online. Company accounts, quotes and delivery across KSA.', 'meta_keywords' => 'PPE Saudi Arabia, safety shoes, FR coverall, safety equipment KSA'],
            ],
            'ar' => [
                'name' => 'هاربر إيدج',
                'description' => 'مورد معتمد لمعدات الوقاية الشخصية والسلامة في المملكة العربية السعودية.',
                'seo' => ['meta_title' => 'هاربر إيدج | معدات الوقاية والسلامة في السعودية', 'meta_description' => 'تسوق أحذية السلامة والملابس المقاومة للهب ونظارات الحماية ومستلزمات سلامة المواقع. حسابات للشركات وعروض أسعار وتوصيل لجميع مناطق المملكة.', 'meta_keywords' => 'معدات السلامة, أحذية سلامة, ملابس مقاومة للهب, السعودية'],
            ],
        ];

        foreach ($translations as $locale => $values) {
            $translation = $channel->translateOrNew($locale);

            $translation->name = $values['name'];
            $translation->description = $values['description'];
            $translation->home_seo = $values['seo'];
            $translation->logo_alt = 'HarbourEdge Company Limited';
        }

        $channel->logo = $this->putFile($this->dataPath.'/brand/logo-dark-horizontal.png', 'channel/'.$channel->id.'/logo.png');
        $channel->favicon = $this->putFile($this->dataPath.'/brand/logo-dark-icon.png', 'channel/'.$channel->id.'/favicon.png');

        $channel->save();
    }

    /**
     * Create the KSA 15% VAT category and rate.
     */
    protected function setupTax(): int
    {
        $rate = $this->taxRateRepository->findOneByField('identifier', 'SA-VAT-15')
            ?? $this->taxRateRepository->create([
                'identifier' => 'SA-VAT-15',
                'is_zip' => 0,
                'country' => 'SA',
                'state' => '*',
                'zip_code' => '*',
                'tax_rate' => 15,
            ]);

        $category = $this->taxCategoryRepository->findOneByField('code', 'ksa-vat-15')
            ?? $this->taxCategoryRepository->create([
                'code' => 'ksa-vat-15',
                'name' => 'KSA VAT 15%',
                'description' => 'Standard Saudi Arabia VAT rate',
            ]);

        $category->tax_rates()->sync([$rate->id]);

        return $category->id;
    }

    /**
     * Store settings: VAT-inclusive prices, Saudi origin, no unconfigured social logins.
     */
    protected function setupConfiguration(): void
    {
        $this->coreConfigRepository->create([
            'locale' => 'en',
            'channel' => 'default',
            'sales' => [
                'taxes' => [
                    'calculation' => [
                        'based_on' => 'shipping_address',
                        'product_prices' => 'including_tax',
                        'shipping_prices' => 'including_tax',
                    ],
                    'default_destination_calculation' => ['country' => 'SA', 'state' => '', 'post_code' => ''],
                    'shopping_cart' => ['display_prices' => 'including_tax', 'display_subtotal' => 'including_tax', 'display_shipping_amount' => 'including_tax', 'show_tax_breakdown' => 1],
                ],
                'shipping' => [
                    'origin' => ['country' => 'SA', 'state' => 'Eastern Province', 'city' => 'Jubail', 'address' => 'Jubail Industrial City', 'zipcode' => '35514'],
                ],
            ],
            'catalog' => [
                'products' => [
                    'storefront' => ['buy_now_button_display' => 1],
                ],
            ],
            'customer' => [
                'settings' => [
                    'social_login' => [
                        'enable_facebook' => 0,
                        'enable_twitter' => 0,
                        'enable_google' => 0,
                        'enable_linkedin' => 0,
                        'enable_github' => 0,
                    ],
                ],
            ],
        ]);
    }

    /**
     * Create the PPE category tree under the root category.
     *
     * @param  array<int, array<string, mixed>>  $categories
     */
    protected function setupCategories(array $categories): void
    {
        $priceAttributeId = DB::table('attributes')->where('code', 'price')->value('id');

        foreach ($categories as $item) {
            $existing = DB::table('category_translations')->where('slug', $item['slug'])->value('category_id');

            $category = $existing
                ? $this->categoryRepository->find($existing)
                : $this->categoryRepository->create([
                    'parent_id' => 1,
                    'position' => $item['position'],
                    'status' => 1,
                    'display_mode' => 'products_only',
                    'en' => ['name' => $item['name_en'], 'slug' => $item['slug'], 'description' => $item['name_en'], 'meta_title' => $item['name_en'].' | HarbourEdge'],
                    'ar' => ['name' => $item['name_ar'], 'slug' => $item['slug'], 'description' => $item['name_ar'], 'meta_title' => $item['name_ar'].' | هاربر إيدج'],
                ]);

            $source = $item['image']
                ? $this->dataPath.'/images/'.$item['image']
                : $this->dataPath.'/icons/'.$item['icon'].'.png';

            $category->filterableAttributes()->sync([$priceAttributeId]);

            $category->logo_path = $this->putImage($source, 'category/'.$category->id.'/'.$item['slug'].'.webp');

            $category->save();

            $this->categoryIds[$item['slug']] = $category->id;
        }
    }

    /**
     * Create the products with photos, prices, stock and Arabic translations.
     *
     * @param  array<int, array<string, mixed>>  $products
     */
    protected function setupProducts(array $products, int $taxCategoryId): void
    {
        $inventorySourceId = DB::table('inventory_sources')->value('id');

        foreach ($products as $item) {
            if ($this->productRepository->findOneByField('sku', $item['sku'])) {
                continue;
            }

            Event::dispatch('catalog.product.create.before');

            $product = $this->productRepository->create([
                'type' => 'simple',
                'attribute_family_id' => 1,
                'sku' => $item['sku'],
            ]);

            Event::dispatch('catalog.product.create.after', $product);

            $slug = str($item['name_en'])->slug()->toString();

            $image = $item['image']
                ? $this->dataPath.'/images/'.$item['image']
                : $this->dataPath.'/icons/'.$item['icon'].'.png';

            $data = [
                'channel' => 'default',
                'locale' => 'en',
                'sku' => $item['sku'],
                'product_number' => $item['sku'],
                'name' => $item['name_en'],
                'url_key' => $slug,
                'short_description' => '<p>'.$item['short_en'].'</p>',
                'description' => $item['desc_en'],
                'meta_title' => $item['name_en'].' | HarbourEdge',
                'meta_description' => $item['short_en'],
                'meta_keywords' => $item['name_en'],
                'price' => $item['price'],
                'special_price' => $item['special_price'],
                'tax_category_id' => $taxCategoryId,
                'status' => 1,
                'visible_individually' => 1,
                'featured' => (int) $item['featured'],
                'new' => (int) $item['new'],
                'guest_checkout' => 1,
                'manage_stock' => 1,
                'allow_rma' => 1,
                'weight' => 1,
                'channels' => [1],
                'categories' => [$this->categoryIds[$item['category']]],
                'inventories' => [$inventorySourceId => 500],
                'images' => [
                    'files' => [
                        new UploadedFile($image, basename($image), mime_content_type($image), null, true),
                    ],
                ],
            ];

            Event::dispatch('catalog.product.update.before', $product->id);

            $product = $this->productRepository->update($data, $product->id);

            $this->productRepository->update([
                'channel' => 'default',
                'locale' => 'ar',
                'name' => $item['name_ar'],
                'url_key' => $slug,
                'short_description' => '<p>'.$item['short_ar'].'</p>',
                'description' => $item['desc_ar'],
                'meta_title' => $item['name_ar'].' | هاربر إيدج',
                'meta_description' => $item['short_ar'],
                'meta_keywords' => $item['name_ar'],
            ], $product->id, ['name', 'url_key', 'short_description', 'description', 'meta_title', 'meta_description', 'meta_keywords']);

            Event::dispatch('catalog.product.update.after', $product->refresh());
        }
    }

    /**
     * Replace the demo homepage with HarbourEdge sections in English and Arabic.
     */
    protected function setupHomepage(): void
    {
        $now = Carbon::now();

        $sectionIds = DB::table('theme_sections')->where('channel_id', 1)->pluck('id');

        DB::table('theme_section_translations')->whereIn('section_id', $sectionIds)->delete();

        DB::table('theme_sections')->whereIn('id', $sectionIds)->delete();

        $category = fn (string $slug) => $this->categoryIds[$slug];

        $sections = [
            ['image_carousel', 'Hero Banners', fn ($locale, $id) => [
                'images' => collect([
                    ['hero', '/'.$this->categoryLink('foot-protection'), ['en' => 'Certified PPE for Saudi industry', 'ar' => 'معدات وقاية معتمدة للصناعة السعودية']],
                    ['fr', '/'.$this->categoryLink('protective-clothing'), ['en' => 'FR and arc-rated workwear', 'ar' => 'ملابس مقاومة للهب']],
                    ['bulk', '/customer/register', ['en' => 'Bulk and project orders', 'ar' => 'طلبات الجملة والمشاريع']],
                ])->map(fn ($banner) => [
                    'image' => 'storage/'.$this->putFile($this->dataPath.'/banners/'.$banner[0].'-'.$locale.'.webp', 'themes/default/sections/'.$id.'/'.$banner[0].'-'.$locale.'.webp'),
                    'link' => $banner[1],
                    'title' => $banner[2][$locale],
                ])->all(),
            ]],
            ['static_content', 'Offer Strip', fn ($locale) => [
                'css' => '.he-strip{background:#0F1B2D;color:#fff;text-align:center;padding:14px 16px;font-size:16px;font-weight:600}.he-strip b{color:#FFAE0B}',
                'html' => $locale === 'ar'
                    ? '<div class="he-strip">توصيل لجميع مناطق المملكة • <b>أسعار خاصة للشركات</b> • اطلب عرض سعر للكميات الكبيرة</div>'
                    : '<div class="he-strip">Delivery across KSA • <b>Special pricing for company accounts</b> • Request a quote for bulk quantities</div>',
            ]],
            ['category_carousel', 'Shop by Category', fn () => [
                'filters' => ['parent_id' => 1, 'sort' => 'asc', 'limit' => 15],
            ]],
            ['product_carousel', 'Best Sellers', fn ($locale) => [
                'title' => $locale === 'ar' ? 'الأكثر مبيعاً' : 'Best Sellers',
                'filters' => ['featured' => 1, 'sort' => 'name-asc', 'limit' => 12],
            ]],
            ['product_carousel', 'Safety Footwear', fn ($locale) => [
                'title' => $locale === 'ar' ? 'أحذية السلامة' : 'Safety Footwear',
                'filters' => ['category_id' => $category('foot-protection'), 'sort' => 'price-asc', 'limit' => 12],
            ]],
            ['product_carousel', 'FR Workwear', fn ($locale) => [
                'title' => $locale === 'ar' ? 'ملابس العمل المقاومة للهب' : 'FR & Protective Workwear',
                'filters' => ['category_id' => $category('protective-clothing'), 'sort' => 'price-asc', 'limit' => 12],
            ]],
            ['product_carousel', 'New Arrivals', fn ($locale) => [
                'title' => $locale === 'ar' ? 'وصل حديثاً' : 'New Arrivals',
                'filters' => ['new' => 1, 'sort' => 'name-asc', 'limit' => 12],
            ]],
            ['services_content', 'Why HarbourEdge', fn ($locale) => [
                'services' => $locale === 'ar' ? [
                    ['service_icon' => 'icon-truck', 'title' => 'توصيل لجميع المناطق', 'description' => 'شحن إلى المواقع والمستودعات في كل أنحاء المملكة'],
                    ['service_icon' => 'icon-gdpr-safe', 'title' => 'منتجات معتمدة', 'description' => 'وفق معايير EN وANSI وNFPA وSASO'],
                    ['service_icon' => 'icon-users', 'title' => 'حسابات الشركات', 'description' => 'أسعار خاصة، موافقات وشروط دفع آجلة'],
                    ['service_icon' => 'icon-support', 'title' => 'اطلب عرض سعر', 'description' => 'عروض أسعار سريعة للكميات الكبيرة والمشاريع'],
                ] : [
                    ['service_icon' => 'icon-truck', 'title' => 'Delivery Across KSA', 'description' => 'Shipping to sites and warehouses nationwide'],
                    ['service_icon' => 'icon-gdpr-safe', 'title' => 'Certified Products', 'description' => 'To EN, ANSI, NFPA and SASO standards'],
                    ['service_icon' => 'icon-users', 'title' => 'Company Accounts', 'description' => 'Custom pricing, approvals and credit terms'],
                    ['service_icon' => 'icon-support', 'title' => 'Request a Quote', 'description' => 'Fast quotes for bulk and project quantities'],
                ],
            ]],
            ['footer_links', 'Footer Links', fn ($locale) => $this->footerLinks($locale)],
        ];

        foreach ($sections as $index => [$type, $name, $options]) {
            $id = DB::table('theme_sections')->insertGetId([
                'theme_code' => 'default',
                'type' => $type,
                'name' => $name,
                'sort_order' => $index + 1,
                'status' => 1,
                'channel_id' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach (['en', 'ar'] as $locale) {
                DB::table('theme_section_translations')->insert([
                    'section_id' => $id,
                    'locale' => $locale,
                    'options' => json_encode($options($locale, $id), JSON_UNESCAPED_UNICODE),
                ]);
            }
        }
    }

    /**
     * Footer link columns for one locale.
     *
     * @return array<string, array<int, array<string, string>>>
     */
    protected function footerLinks(string $locale): array
    {
        $url = rtrim(config('app.url'), '/');

        $ar = $locale === 'ar';

        return [
            'column_1' => [
                ['url' => $url.'/page/about-us', 'title' => $ar ? 'من نحن' : 'About Us', 'sort_order' => 1],
                ['url' => $url.'/contact-us', 'title' => $ar ? 'اتصل بنا' : 'Contact Us', 'sort_order' => 2],
                ['url' => $url.'/customer/register', 'title' => $ar ? 'افتح حساب شركة' : 'Open a Company Account', 'sort_order' => 3],
            ],
            'column_2' => [
                ['url' => $url.'/page/shipping-policy', 'title' => $ar ? 'سياسة الشحن' : 'Shipping Policy', 'sort_order' => 1],
                ['url' => $url.'/page/return-policy', 'title' => $ar ? 'سياسة الاسترجاع' : 'Return Policy', 'sort_order' => 2],
                ['url' => $url.'/page/privacy-policy', 'title' => $ar ? 'سياسة الخصوصية' : 'Privacy Policy', 'sort_order' => 3],
                ['url' => $url.'/page/terms-conditions', 'title' => $ar ? 'الشروط والأحكام' : 'Terms & Conditions', 'sort_order' => 4],
            ],
        ];
    }

    /**
     * The storefront path of a category.
     */
    protected function categoryLink(string $slug): string
    {
        return $slug;
    }

    /**
     * Copy a file to the public disk as-is and return its storage path.
     */
    protected function putFile(string $source, string $target): string
    {
        Storage::put($target, file_get_contents($source));

        return $target;
    }

    /**
     * Copy an image to the public disk as webp and return its storage path.
     */
    protected function putImage(string $source, string $target): string
    {
        Storage::put($target, (string) image_manager()->read($source)->encodeByExtension('webp'));

        return $target;
    }
}
