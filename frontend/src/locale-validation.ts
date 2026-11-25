const patterns: [RegExp, string][] = [
  [/^The (.+?) field is required(?: when .+| unless .+)?\.$/, '{1} ကို မဖြစ်မနေ ဖြည့်ပါ။'],
  [/^The (.+?) field must be accepted(?: when .+)?\.$/, '{1} ကို အတည်ပြုရွေးချယ်ပါ။'],
  [/^The (.+?) field must be a valid email address\.$/, '{1} တွင် မှန်ကန်သော အီးမေးလ်လိပ်စာ ဖြည့်ပါ။'],
  [/^The (.+?) field must be a valid URL\.$/, '{1} တွင် မှန်ကန်သော ဝက်ဘ်လိပ်စာ ဖြည့်ပါ။'],
  [/^The (.+?) field must be an integer\.$/, '{1} တွင် ပြည့်ကိန်း ဖြည့်ပါ။'],
  [/^The (.+?) field must be a number\.$/, '{1} တွင် ဂဏန်း ဖြည့်ပါ။'],
  [/^The (.+?) field must be a string\.$/, '{1} တွင် စာသား ဖြည့်ပါ။'],
  [/^The (.+?) field must be true or false\.$/, '{1} အတွက် ဖွင့် သို့မဟုတ် ပိတ် ရွေးပါ။'],
  [/^The (.+?) field must be an array\.$/, '{1} အတွက် စာရင်းပုံစံ ဖြစ်ရမည်။'],
  [/^The (.+?) field must be a valid date\.$/, '{1} တွင် မှန်ကန်သော ရက်စွဲ ဖြည့်ပါ။'],
  [/^The (.+?) field must be a valid timezone\.$/, '{1} တွင် မှန်ကန်သော စံတော်ချိန် ရွေးပါ။'],
  [/^The (.+?) field must match the format (.+)\.$/, '{1} ကို {2} ပုံစံအတိုင်း ဖြည့်ပါ။'],
  [/^The (.+?) field format is invalid\.$/, '{1} ၏ ပုံစံ မမှန်ပါ။'],
  [/^The (.+?) field is invalid\.$/, '{1} မမှန်ပါ။'],
  [/^The selected (.+?) is invalid\.$/, 'ရွေးထားသော {1} မမှန်ပါ။'],
  [/^The (.+?) has already been taken\.$/, '{1} ကို အသုံးပြုထားပြီးဖြစ်သည်။'],
  [/^The (.+?) field confirmation does not match\.$/, '{1} ပြန်ဖြည့်ထားမှု မကိုက်ညီပါ။'],
  [/^The (.+?) field has a duplicate value\.$/, '{1} တွင် ထပ်နေသောတန်ဖိုး ပါဝင်သည်။'],
  [/^The (.+?) field has invalid image dimensions\.$/, '{1} ၏ ဓာတ်ပုံအရွယ်အစား မမှန်ပါ။'],
  [/^The (.+?) field must be an image\.$/, '{1} အတွက် ဓာတ်ပုံ ဖြစ်ရမည်။'],
  [/^The (.+?) field must be a file\.$/, '{1} အတွက် ဖိုင် ဖြစ်ရမည်။'],
  [/^The (.+?) field must be a file of type: (.+)\.$/, '{1} အတွက် {2} အမျိုးအစားဖိုင် ဖြစ်ရမည်။'],
  [/^The (.+?) failed to upload\.$/, '{1} ဖိုင်တင်ခြင်း မအောင်မြင်ပါ။'],
  [/^The (.+?) field must be at least (\d+) characters\.$/, '{1} တွင် အနည်းဆုံး စာလုံး {2} လုံး ရှိရမည်။'],
  [/^The (.+?) field must not be greater than (\d+) characters\.$/, '{1} တွင် စာလုံး {2} လုံးထက် မပိုရပါ။'],
  [/^The (.+?) field must be (\d+) characters\.$/, '{1} တွင် စာလုံး {2} လုံး ရှိရမည်။'],
  [/^The (.+?) field must be at least (\d+) kilobytes\.$/, '{1} သည် အနည်းဆုံး {2} KB ရှိရမည်။'],
  [/^The (.+?) field must not be greater than (\d+) kilobytes\.$/, '{1} သည် {2} KB ထက် မပိုရပါ။'],
  [/^The (.+?) field must be at least ([-\d.]+)\.$/, '{1} သည် အနည်းဆုံး {2} ရှိရမည်။'],
  [/^The (.+?) field must not be greater than ([-\d.]+)\.$/, '{1} သည် {2} ထက် မပိုရပါ။'],
  [/^The (.+?) field must have at least (\d+) items\.$/, '{1} တွင် အနည်းဆုံး ပစ္စည်း {2} ခု ရှိရမည်။'],
  [/^The (.+?) field must not have more than (\d+) items\.$/, '{1} တွင် ပစ္စည်း {2} ခုထက် မပိုရပါ။'],
  [/^The (.+?) field must be between ([-\d.]+) and ([-\d.]+)\.$/, '{1} သည် {2} နှင့် {3} ကြား ရှိရမည်။'],
  [/^The (.+?) field must be between (\d+) and (\d+) characters\.$/, '{1} တွင် စာလုံး {2} မှ {3} လုံးအတွင်း ရှိရမည်။'],
  [/^The (.+?) field must be between (\d+) and (\d+) kilobytes\.$/, '{1} သည် {2} မှ {3} KB အတွင်း ရှိရမည်။'],
  [/^The (.+?) field must have between (\d+) and (\d+) items\.$/, '{1} တွင် ပစ္စည်း {2} မှ {3} ခုအတွင်း ရှိရမည်။'],
  [/^The (.+?) field must be a date after or equal to (.+)\.$/, '{1} သည် {2} နှင့် တူညီသောရက် သို့မဟုတ် နောက်ကျသောရက် ဖြစ်ရမည်။'],
  [/^The (.+?) field must be a date after (.+)\.$/, '{1} သည် {2} ထက် နောက်ကျသောရက် ဖြစ်ရမည်။'],
  [/^The (.+?) field must be a date before or equal to (.+)\.$/, '{1} သည် {2} နှင့် တူညီသောရက် သို့မဟုတ် စောသောရက် ဖြစ်ရမည်။'],
  [/^The (.+?) field must be a date before (.+)\.$/, '{1} သည် {2} ထက် စောသောရက် ဖြစ်ရမည်။'],
  [/^The (.+?) field must be greater than ([-\d.]+)\.$/, '{1} သည် {2} ထက် ကြီးရမည်။'],
  [/^The (.+?) field must be greater than or equal to ([-\d.]+)\.$/, '{1} သည် {2} နှင့် တူညီ သို့မဟုတ် ပိုကြီးရမည်။'],
  [/^The (.+?) field must be less than ([-\d.]+)\.$/, '{1} သည် {2} ထက် ငယ်ရမည်။'],
  [/^The (.+?) field must be less than or equal to ([-\d.]+)\.$/, '{1} သည် {2} နှင့် တူညီ သို့မဟုတ် ပိုငယ်ရမည်။'],
];

export function translateValidation(message: string, messages: Record<string, string>): string | undefined {
  const attributes: Record<string, string> = {
    'category id': 'Category', 'table id': 'Table', 'menu item id': 'Menu item', 'order id': 'Order', 'item id': 'Item',
    'shift id': 'Cashier shifts', 'payment id': 'Payment', 'billing group id': 'Bill portion',
    'variant id': 'Size', 'addon ids': 'Add-ons', method: 'Payment method', 'entry date': 'Entry date',
    'business name': 'Business name', 'business code': 'Business code', 'other payment label': 'Other payment label',
    'password confirmation': 'Confirm password', 'current password': 'Password', 'tax rate bps': 'Tax rate (%)',
    'service rate bps': 'Service charge rate (%)', 'tax label': 'Tax name', 'service charge label': 'Service charge name',
    'receipt paper width': 'Paper width', 'receipt title': 'Receipt title', 'guest menu enabled': 'Enable public guest menu',
    'guest ordering enabled': 'Accept guest order requests', 'guest direct to kitchen': 'Send customer orders directly to kitchen',
    'created at': 'Date', 'updated at': 'Date', 'counted cash': 'Counted cash', 'opening cash': 'Opening cash',
    image: 'Food photo', screenshot: 'Screenshot (optional, up to 4 MB)', receipt: 'Payment proof (optional, up to 4 MB)',
    from: 'From', to: 'To', q: 'Search', 'per page': 'Page', 'options version': 'Sizes & add-ons',
  };
  const fieldLabel = (name: string) => {
    const clean = name.replace(/_/g, ' ');
    const title = clean.charAt(0).toUpperCase() + clean.slice(1);
    const leaf = clean.split('.').at(-1)!;
    const key = attributes[clean] ?? attributes[leaf] ?? title;
    return messages[key] ?? messages[clean] ?? messages[title] ?? messages[leaf.charAt(0).toUpperCase() + leaf.slice(1)] ?? `အကွက် (${name})`;
  };
  for (const [pattern, template] of patterns) {
    const match = pattern.exec(message);
    if (!match) continue;
    const values = match.slice(1); values[0] = fieldLabel(values[0]);
    return template.replace(/\{(\d+)\}/g, (_, index: string) => values[Number(index) - 1]);
  }
  const parts = message.split(/(?<=\.) (?=The )/);
  if (parts.length > 1) {
    const translated = parts.map(part => translateValidation(part, messages) ?? messages[part] ?? part);
    return translated.join(' ');
  }
  return undefined;
}
