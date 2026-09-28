export function normalizeTicketCode(raw) {
  const value = String(raw || '').toUpperCase().trim();
  const match = value.match(/ROI-[A-Z0-9]{4}-[A-Z0-9]{4}/);
  if (match) return match[0];
  return value.replace(/\s+/g, '');
}

export function resultTone(result) {
  switch (result) {
    case 'admitted':
    case 'ready':
      return 'ok';
    case 'already':
      return 'warn';
    case 'void':
    case 'unpaid':
    case 'not_found':
      return 'bad';
    default:
      return 'neutral';
  }
}
