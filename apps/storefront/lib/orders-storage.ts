import type { Fulfillment } from './types';

export interface StoredOrderItem {
  id?: number;
  name: string;
  quantity: number;
  unitPriceCents?: number;
  totalCents: number;
}

export interface StoredOrder {
  id: number;
  number: number;
  tenantSlug: string;
  status: string;
  fulfillment: Fulfillment;
  paymentMethod: string;
  totalCents: number;
  placedAt: string;
  customerName?: string;
  customerPhone?: string;
  items: StoredOrderItem[];
}

const STORAGE_PREFIX = 'tomenu_orders_';

export function getStoredOrders(tenantSlug: string): StoredOrder[] {
  if (typeof window === 'undefined') return [];
  try {
    const raw = localStorage.getItem(`${STORAGE_PREFIX}${tenantSlug}`);
    if (!raw) return [];
    const parsed = JSON.parse(raw);
    return Array.isArray(parsed) ? parsed : [];
  } catch (error) {
    console.error('Failed to read stored orders from localStorage:', error);
    return [];
  }
}

export function addStoredOrder(tenantSlug: string, order: StoredOrder): void {
  if (typeof window === 'undefined') return;
  try {
    const current = getStoredOrders(tenantSlug);
    // Evita duplicatas se por algum motivo for chamado mais de uma vez
    const filtered = current.filter((o) => o.id !== order.id);
    const updated = [order, ...filtered];
    localStorage.setItem(`${STORAGE_PREFIX}${tenantSlug}`, JSON.stringify(updated));
  } catch (error) {
    console.error('Failed to save order to localStorage:', error);
  }
}

export function updateStoredOrderStatus(
  tenantSlug: string,
  orderId: number,
  updatedFields: Partial<StoredOrder>
): void {
  if (typeof window === 'undefined') return;
  try {
    const current = getStoredOrders(tenantSlug);
    const updated = current.map((order) => {
      if (order.id === orderId) {
        return { ...order, ...updatedFields };
      }
      return order;
    });
    localStorage.setItem(`${STORAGE_PREFIX}${tenantSlug}`, JSON.stringify(updated));
  } catch (error) {
    console.error('Failed to update order status in localStorage:', error);
  }
}
