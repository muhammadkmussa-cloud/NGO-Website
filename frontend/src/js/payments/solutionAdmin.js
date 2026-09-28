export function moveSolutionIds(ids, id, dir) {
  const next = [...ids];
  const idx = next.findIndex((x) => String(x) === String(id));
  const dest = idx + dir;
  if (idx < 0 || dest < 0 || dest >= next.length) return next;
  [next[idx], next[dest]] = [next[dest], next[idx]];
  return next;
}

export function newSolutionInquiryCount(rows) {
  return (rows || []).filter((r) => r.status === 'New').length;
}
