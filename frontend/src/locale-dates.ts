const longMonths = ['ဇန်နဝါရီ', 'ဖေဖော်ဝါရီ', 'မတ်', 'ဧပြီ', 'မေ', 'ဇွန်', 'ဇူလိုင်', 'ဩဂုတ်', 'စက်တင်ဘာ', 'အောက်တိုဘာ', 'နိုဝင်ဘာ', 'ဒီဇင်ဘာ'];
const shortMonths = ['ဇန်', 'ဖေ', 'မတ်', 'ဧ', 'မေ', 'ဇွန်', 'ဇူ', 'ဩ', 'စက်', 'အောက်', 'နို', 'ဒီ'];
const weekdays: Record<string, string> = { Sunday: 'တနင်္ဂနွေ', Monday: 'တနင်္လာ', Tuesday: 'အင်္ဂါ', Wednesday: 'ဗုဒ္ဓဟူး', Thursday: 'ကြာသပတေး', Friday: 'သောကြာ', Saturday: 'စနေ' };

/** Browser ICU bundles do not always include Myanmar, so derive local calendar parts using the supported English locale. */
export function formatLocalizedDate(date: Date, locale: 'en' | 'my', options: Intl.DateTimeFormatOptions): string {
  if (locale === 'en') return new Intl.DateTimeFormat('en-GB', options).format(date);
  const fields = new Intl.DateTimeFormat('en-GB', {
    ...options, month: options.month ? 'numeric' : undefined,
    weekday: options.weekday ? 'long' : undefined, hourCycle: 'h23',
  }).formatToParts(date);
  const part = (name: Intl.DateTimeFormatPartTypes) => fields.find(field => field.type === name)?.value ?? '';
  const month = Number(part('month')) - 1;
  const dateParts = [
    options.weekday ? weekdays[part('weekday')] : '',
    options.day ? options.day === '2-digit' ? part('day').padStart(2, '0') : String(Number(part('day'))) : '',
    options.month ? options.month === 'long' ? longMonths[month] : options.month === 'short' || options.month === 'narrow' ? shortMonths[month] : options.month === '2-digit' ? String(month + 1).padStart(2, '0') : String(month + 1) : '',
    options.year ? part('year') : '',
  ].filter(Boolean);
  const timeParts = [options.hour ? part('hour') : '', options.minute ? part('minute') : '', options.second ? part('second') : ''].filter(Boolean);
  return [dateParts.join(' '), timeParts.join(':'), options.timeZoneName ? part('timeZoneName') : ''].filter(Boolean).join(' ');
}
