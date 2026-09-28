export const INQUIRY_STATUSES = ['New', 'In Review', 'Quoted', 'Won', 'Lost', 'Resolved'];

export const INQUIRY_TRANSITIONS = {
  New: ['In Review', 'Lost', 'Resolved'],
  'In Review': ['Quoted', 'Lost', 'Resolved'],
  Quoted: ['Won', 'Lost', 'Resolved', 'In Review'],
  Won: ['Resolved'],
  Lost: ['In Review', 'Resolved'],
  Resolved: ['In Review']
};

export function nextInquiryStatuses(current) {
  return INQUIRY_TRANSITIONS[current] || [];
}

export function canMoveInquiry(from, to) {
  return from === to || nextInquiryStatuses(from).includes(to);
}

export function isOpenInquiry(status) {
  return ['New', 'In Review', 'Quoted'].includes(status);
}
