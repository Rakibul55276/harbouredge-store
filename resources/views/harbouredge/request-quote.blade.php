<button
    type="submit"
    class="mt-3 flex w-full max-w-[470px] items-center justify-center gap-2 rounded-xl border-2 px-8 py-3 text-base font-semibold transition-all max-sm:rounded-lg max-sm:py-1.5 max-sm:text-sm"
    style="border-color: #FFAE0B; background-color: #FFF6E0; color: #0F1B2D;"
    :disabled="isStoring.addToCart"
    @click="is_buy_now = 0; $refs.formData.dataset.requestQuote = '1'"
>
    <span class="icon-email text-xl"></span>

    @lang('shop::app.harbouredge.quote.request')
</button>

<p class="mt-2 max-w-[470px] text-xs text-zinc-500">
    @lang('shop::app.harbouredge.quote.info')
</p>

@pushOnce('scripts')
    <script>
        window.addEventListener('load', () => {
            app.config.globalProperties.$emitter.on('update-mini-cart', () => {
                if (document.querySelector('form[data-request-quote="1"]')) {
                    window.location.href = "{{ route('shop.checkout.cart.index') }}";
                }
            });
        });
    </script>
@endPushOnce
