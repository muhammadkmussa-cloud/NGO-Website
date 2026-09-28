export function filterTicketOrders(orders, { search = '', status = '' } = {}) {
  const q = String(search).toLowerCase().trim();
  return (orders || []).filter((o) => {
    const matchesSearch = !q
      || String(o.reference || '').toLowerCase().includes(q)
      || String(o.buyer_name || '').toLowerCase().includes(q)
      || String(o.buyer_email || '').toLowerCase().includes(q);
    const matchesStatus = !status || o.status === status;
    return matchesSearch && matchesStatus;
  });
}

export function checkInRate(issued, checkedIn) {
  if (!issued) return 0;
  return Math.round((checkedIn / issued) * 1000) / 1000;
}
