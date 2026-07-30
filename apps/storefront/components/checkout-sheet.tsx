'use client';

import { useEffect, useRef, useState } from 'react';
import { formatMoney, submitOrder } from '@/lib/api';
import type { TenantInfo } from '@/lib/types';
import { useCart } from './cart-provider';

type Step = 'cart' | 'details' | 'done';

const PAYMENT_LABELS: Record<string, string> = {
  cash: 'Dinheiro na entrega',
  card_on_delivery: 'Cartão na entrega',
  stripe_card: 'Cartão pelo site',
  stripe_pix: 'Pix pelo site',
};

export function CheckoutSheet({
  tenant,
  tenantSlug,
  onClose,
}: {
  tenant: TenantInfo;
  tenantSlug: string;
  onClose: () => void;
}) {
  const dialogRef = useRef<HTMLDialogElement>(null);
  const { lines, subtotalCents, setQuantity, clear } = useCart();

  const [step, setStep] = useState<Step>('cart');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [orderNumber, setOrderNumber] = useState<number | null>(null);

  const [form, setForm] = useState({
    name: '',
    phone: '',
    fulfillment: 'delivery' as 'delivery' | 'pickup',
    street: '',
    number: '',
    district: '',
    city: '',
    state: '',
    paymentMethod: tenant.paymentMethods[0] ?? 'cash',
    notes: '',
  });

  useEffect(() => {
    dialogRef.current?.showModal();
  }, []);

  const deliveryFee =
    form.fulfillment === 'delivery' ? (tenant.deliveryConfig.fee_cents ?? 0) : 0;
  const total = subtotalCents + deliveryFee;

  function update(field: keyof typeof form, value: string) {
    setForm((current) => ({ ...current, [field]: value }));
  }

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    setSubmitting(true);
    setError(null);

    try {
      // Envia apenas IDs e quantidades. Todo preço é recalculado pelo servidor
      // a partir do banco — o cliente não tem voz sobre valores.
      const result = await submitOrder(tenantSlug, {
        customer: { name: form.name, phone: form.phone },
        fulfillment: form.fulfillment,
        address:
          form.fulfillment === 'delivery'
            ? {
                street: form.street,
                number: form.number,
                district: form.district,
                city: form.city,
                state: form.state.toUpperCase(),
              }
            : undefined,
        payment_method: form.paymentMethod,
        items: lines.map((line) => ({
          product_id: line.productId,
          quantity: line.quantity,
          modifier_ids: line.modifiers.map((modifier) => modifier.id),
        })),
        notes: form.notes || undefined,
      });

      setOrderNumber(result.number);
      setStep('done');
      clear();
    } catch (caught) {
      setError(
        caught instanceof Error
          ? caught.message
          : 'Não foi possível enviar o pedido.',
      );
    } finally {
      setSubmitting(false);
    }
  }

  const inputClass =
    'w-full border bg-transparent px-3 py-2.5 text-sm outline-none transition-colors placeholder:text-[var(--ink-subtle)] focus:border-[rgb(var(--brand))]';
  const inputStyle = {
    borderRadius: 'calc(var(--radius) * 0.55)',
    borderColor: 'var(--hairline)',
  };

  return (
    <dialog
      ref={dialogRef}
      onClose={onClose}
      className="m-0 max-h-[94dvh] w-full max-w-lg self-end bg-transparent p-0 backdrop:bg-black/45 sm:m-auto sm:self-center"
    >
      <div
        className="animate-slide-up flex max-h-[94dvh] flex-col overflow-hidden bg-[rgb(var(--surface))] text-[rgb(var(--ink))] sm:animate-rise"
        style={{ borderRadius: 'var(--radius)' }}
      >
        <header className="flex items-center justify-between border-b border-[var(--hairline)] px-5 py-3.5">
          <h2 className="text-base font-semibold">
            {step === 'cart' && 'Seu pedido'}
            {step === 'details' && 'Entrega e pagamento'}
            {step === 'done' && 'Pedido confirmado'}
          </h2>

          <button
            type="button"
            onClick={() => dialogRef.current?.close()}
            aria-label="Fechar"
            className="grid size-8 place-items-center rounded-full text-muted transition-colors hover:bg-[var(--hairline)]"
          >
            ✕
          </button>
        </header>

        {step === 'done' ? (
          <div className="px-5 py-12 text-center">
            <div
              className="mx-auto grid size-14 place-items-center rounded-full text-2xl"
              style={{
                background: 'rgb(var(--brand-soft))',
                color: 'rgb(var(--brand))',
              }}
            >
              ✓
            </div>

            <p className="mt-4 text-lg font-semibold">
              Pedido #{orderNumber} recebido
            </p>
            <p className="mt-1.5 text-sm text-muted">
              {tenant.name} já foi avisado e vai preparar seu pedido.
            </p>

            <button
              type="button"
              onClick={() => dialogRef.current?.close()}
              className="mt-7 w-full px-4 py-3 text-sm font-semibold text-white"
              style={{
                background: 'rgb(var(--brand))',
                borderRadius: 'calc(var(--radius) * 0.6)',
              }}
            >
              Concluir
            </button>
          </div>
        ) : (
          <form onSubmit={handleSubmit} className="flex min-h-0 flex-col">
            <div className="min-h-0 flex-1 overflow-y-auto px-5 py-4">
              {step === 'cart' && (
                <ul className="grid gap-2.5">
                  {lines.map((line) => (
                    <li
                      key={line.key}
                      className="flex items-start gap-3 border-b border-[var(--hairline)] pb-2.5 last:border-0"
                    >
                      <div className="min-w-0 flex-1">
                        <p className="text-sm font-medium">{line.name}</p>

                        {line.modifiers.length > 0 && (
                          <p className="mt-0.5 text-xs text-subtle">
                            {line.modifiers.map((m) => m.name).join(' · ')}
                          </p>
                        )}

                        <div className="mt-1.5 flex items-center gap-1">
                          <button
                            type="button"
                            aria-label="Diminuir"
                            onClick={() =>
                              setQuantity(line.key, line.quantity - 1)
                            }
                            className="grid size-7 place-items-center rounded-md text-muted hover:bg-[var(--hairline)]"
                          >
                            −
                          </button>
                          <span className="w-6 text-center text-sm tabular-nums">
                            {line.quantity}
                          </span>
                          <button
                            type="button"
                            aria-label="Aumentar"
                            onClick={() =>
                              setQuantity(line.key, line.quantity + 1)
                            }
                            className="grid size-7 place-items-center rounded-md text-muted hover:bg-[var(--hairline)]"
                          >
                            +
                          </button>
                        </div>
                      </div>

                      <span className="text-sm font-medium tabular-nums">
                        {formatMoney(line.unitPriceCents * line.quantity)}
                      </span>
                    </li>
                  ))}
                </ul>
              )}

              {step === 'details' && (
                <div className="grid gap-3.5">
                  <div className="grid gap-1.5">
                    <label htmlFor="name" className="text-xs font-medium text-muted">
                      Nome
                    </label>
                    <input
                      id="name"
                      required
                      value={form.name}
                      onChange={(e) => update('name', e.target.value)}
                      className={inputClass}
                      style={inputStyle}
                      placeholder="Seu nome"
                    />
                  </div>

                  <div className="grid gap-1.5">
                    <label htmlFor="phone" className="text-xs font-medium text-muted">
                      WhatsApp
                    </label>
                    <input
                      id="phone"
                      required
                      inputMode="tel"
                      value={form.phone}
                      onChange={(e) => update('phone', e.target.value)}
                      className={inputClass}
                      style={inputStyle}
                      placeholder="(11) 99999-9999"
                    />
                  </div>

                  <div className="grid grid-cols-2 gap-2">
                    {(['delivery', 'pickup'] as const).map((option) => (
                      <button
                        key={option}
                        type="button"
                        onClick={() => update('fulfillment', option)}
                        className="border px-3 py-2.5 text-sm font-medium transition-colors"
                        style={{
                          borderRadius: 'calc(var(--radius) * 0.55)',
                          borderColor:
                            form.fulfillment === option
                              ? 'rgb(var(--brand))'
                              : 'var(--hairline)',
                          background:
                            form.fulfillment === option
                              ? 'rgb(var(--brand-soft) / 0.6)'
                              : 'transparent',
                        }}
                      >
                        {option === 'delivery' ? 'Entrega' : 'Retirada'}
                      </button>
                    ))}
                  </div>

                  {form.fulfillment === 'delivery' && (
                    <>
                      <div className="grid grid-cols-[1fr_88px] gap-2">
                        <input
                          required
                          value={form.street}
                          onChange={(e) => update('street', e.target.value)}
                          className={inputClass}
                          style={inputStyle}
                          placeholder="Rua"
                          aria-label="Rua"
                        />
                        <input
                          value={form.number}
                          onChange={(e) => update('number', e.target.value)}
                          className={inputClass}
                          style={inputStyle}
                          placeholder="Nº"
                          aria-label="Número"
                        />
                      </div>

                      <input
                        value={form.district}
                        onChange={(e) => update('district', e.target.value)}
                        className={inputClass}
                        style={inputStyle}
                        placeholder="Bairro"
                        aria-label="Bairro"
                      />

                      <div className="grid grid-cols-[1fr_72px] gap-2">
                        <input
                          required
                          value={form.city}
                          onChange={(e) => update('city', e.target.value)}
                          className={inputClass}
                          style={inputStyle}
                          placeholder="Cidade"
                          aria-label="Cidade"
                        />
                        <input
                          required
                          maxLength={2}
                          value={form.state}
                          onChange={(e) => update('state', e.target.value)}
                          className={`${inputClass} uppercase`}
                          style={inputStyle}
                          placeholder="UF"
                          aria-label="Estado"
                        />
                      </div>
                    </>
                  )}

                  <fieldset className="grid gap-1.5">
                    <legend className="pb-1 text-xs font-medium text-muted">
                      Forma de pagamento
                    </legend>

                    {tenant.paymentMethods.map((method) => (
                      <label
                        key={method}
                        className="flex cursor-pointer items-center gap-3 border p-3 text-sm"
                        style={{
                          borderRadius: 'calc(var(--radius) * 0.55)',
                          borderColor:
                            form.paymentMethod === method
                              ? 'rgb(var(--brand))'
                              : 'var(--hairline)',
                          background:
                            form.paymentMethod === method
                              ? 'rgb(var(--brand-soft) / 0.6)'
                              : 'transparent',
                        }}
                      >
                        <input
                          type="radio"
                          name="payment"
                          checked={form.paymentMethod === method}
                          onChange={() => update('paymentMethod', method)}
                          className="size-4 accent-[rgb(var(--brand))]"
                        />
                        {PAYMENT_LABELS[method] ?? method}
                      </label>
                    ))}
                  </fieldset>

                  <textarea
                    value={form.notes}
                    onChange={(e) => update('notes', e.target.value)}
                    className={`${inputClass} min-h-20 resize-none`}
                    style={inputStyle}
                    placeholder="Observações (opcional)"
                    aria-label="Observações"
                  />
                </div>
              )}
            </div>

            <footer className="border-t border-[var(--hairline)] px-5 py-4">
              <dl className="mb-3 grid gap-1 text-sm">
                <div className="flex justify-between text-muted">
                  <dt>Subtotal</dt>
                  <dd className="tabular-nums">{formatMoney(subtotalCents)}</dd>
                </div>

                {form.fulfillment === 'delivery' && (
                  <div className="flex justify-between text-muted">
                    <dt>Entrega</dt>
                    <dd className="tabular-nums">
                      {deliveryFee === 0 ? 'Grátis' : formatMoney(deliveryFee)}
                    </dd>
                  </div>
                )}

                <div className="flex justify-between pt-1 text-base font-semibold">
                  <dt>Total</dt>
                  <dd className="tabular-nums">{formatMoney(total)}</dd>
                </div>
              </dl>

              {error && (
                <p
                  role="alert"
                  className="mb-2.5 px-3 py-2 text-xs"
                  style={{
                    background: '#fef2f2',
                    color: '#b91c1c',
                    borderRadius: 'calc(var(--radius) * 0.5)',
                  }}
                >
                  {error}
                </p>
              )}

              {step === 'cart' ? (
                <button
                  type="button"
                  onClick={() => setStep('details')}
                  className="w-full px-4 py-3 text-sm font-semibold text-white transition-opacity hover:opacity-90"
                  style={{
                    background: 'rgb(var(--brand))',
                    borderRadius: 'calc(var(--radius) * 0.6)',
                  }}
                >
                  Continuar
                </button>
              ) : (
                <button
                  type="submit"
                  disabled={submitting}
                  className="w-full px-4 py-3 text-sm font-semibold text-white transition-opacity hover:opacity-90 disabled:opacity-60"
                  style={{
                    background: 'rgb(var(--brand))',
                    borderRadius: 'calc(var(--radius) * 0.6)',
                  }}
                >
                  {submitting ? 'Enviando…' : `Confirmar · ${formatMoney(total)}`}
                </button>
              )}
            </footer>
          </form>
        )}
      </div>
    </dialog>
  );
}
