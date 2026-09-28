const SALE_LABELS = {
  on_sale: 'On sale',
  inactive: 'Hidden',
  not_started: 'Sales not started',
  ended: 'Sales ended',
  sold_out: 'Sold out',
  event_closed: 'Sales closed',
  event_sold_out: 'Event sold out'
};

export function saleStateLabel(state) {
  return SALE_LABELS[state] || 'Unavailable';
}

export function maxPurchasable(type) {
  const perOrder = Number(type?.max_per_order || 10);
  const remaining = type?.unlimited || type?.remaining == null ? perOrder : Number(type.remaining);
  return Math.max(0, Math.min(perOrder, remaining, 20));
}

export function canIncrement(type, currentQty) {
  return Boolean(type?.on_sale) && currentQty < maxPurchasable(type);
}
