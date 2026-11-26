import { myCommon } from './my-common';
import { myFinance } from './my-finance';
import { myOperations } from './my-operations';
import { mySettings } from './my-settings';
import { myPayments } from './my-payments';
import { myReports } from './my-reports';
import { mySupport } from './my-support';
import { myDetails } from './my-details';
import { myErrors } from './my-errors';
import { myPrinters } from './my-printers';

// Burmese terminology: MyTel.POS restaurant ordering/payment guides and KBZPay merchant guidance.
// https://www.mytel-pos.com.mm/docs/my/13-restaurant/pos-sales
// https://www.mytel-pos.com.mm/docs/my/13-restaurant/payment
// https://static.kbzpay.com/public/static/prod/html/Merchant_Merchantmy.html
// Receipt terminology: https://www.ird.gov.mm/announcement-lists/41
// Keep bill (ဘီလ်), receipt (ပြေစာ), change (အမ်းငွေ) and refund (ငွေပြန်အမ်းမှု) distinct.
// Cash shifts use the established product name ကောင်တာစာရင်း. Preserve English keys and placeholders.
export const myMessages: Record<string, string> = { ...myCommon, ...myFinance, ...myOperations, ...mySettings, ...myPayments, ...myReports, ...mySupport, ...myDetails, ...myErrors, ...myPrinters };

for (const [code, label] of Object.entries({day:'Day',week:'Week',month:'Month',year:'Year',system:'System',light:'Light',dark:'Dark',active:'Active',inactive:'Inactive',available:'Available',occupied:'Occupied',open:'Open',closed:'Closed',completed:'Completed',cancelled:'Cancelled',merged:'Merged',paid:'Paid',unpaid:'Unpaid',draft:'Not sent',sent:'Sent',preparing:'Preparing',ready:'Ready',served:'Served',collected:'Collected',pending:'Pending',approved:'Approved',rejected:'Rejected',expired:'Expired',cash:'Cash',other:'Other',mixed:'Mixed',manager:'Manager',waiter:'Waiter',cashier:'Cashier',kitchen:'Kitchen',income:'Income',expense:'Expense',trial:'Trial',accepted:'Accepted',resolved:'Resolved',announcement:'Announcement',tutorial:'Tutorial',reviewed:'Reviewed',low:'Low',normal:'Normal',high:'High',in_review:'in review',submitted:'Submitted',takeaway:'Takeaway',dine_in:'Dine-in'})) { myMessages[code] = myMessages[label] ?? label; }
