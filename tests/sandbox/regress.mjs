// regress.mjs <surface: box|receipt|payform> <mode: element|iframe> <outcome> [waitMs]
import { chromium } from '/Users/majhar/AGL/vezmo/user/node_modules/playwright-core/index.mjs';
import { execFileSync } from 'node:child_process';
const wp = (c) => execFileSync('docker',['exec','wc-test-cli-1','wp','eval',c],{encoding:'utf8'})
  .split('\n').filter(l=>!l.includes('sendmail')).join('\n').trim();
const [surface='box', mode='element', outcome='success', waitMs='14000'] = process.argv.slice(2);
execFileSync('docker',['exec','wc-test-cli-1','wp','option','update','mockpay_outcome',outcome]);
try { execFileSync('docker',['exec','wc-test-cli-1','wp','transient','delete','mockpay_created']); } catch {}
execFileSync('docker',['exec','wc-test-cli-1','wp','eval',
  `$s=get_option("woocommerce_vezmopay_settings"); $s["integration_mode"]="${mode}"; update_option("woocommerce_vezmopay_settings",$s);`]);

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1280, height: 950 } });
const trace = [];
page.on('console', m => { const t = m.text(); if (t.includes('[VezmoPay]')) trace.push(t.replace('[VezmoPay] ','').slice(0,110)); });
const tag = `[${surface}/${mode}/${outcome}]`;

async function seedOrder(status) {
  return wp(`
    $o = wc_create_order(); $o->add_product( wc_get_product(10), 1 );
    $o->set_billing_first_name("Ada"); $o->set_billing_last_name("L"); $o->set_billing_address_1("1 St");
    $o->set_billing_city("SF"); $o->set_billing_state("CA"); $o->set_billing_postcode("94105");
    $o->set_billing_country("US"); $o->set_billing_email("a@b.test"); $o->set_payment_method("vezmopay");
    $o->calculate_totals(); $o->set_status("${status}"); $o->save();
    echo $o->get_id() . "|" . $o->get_order_key();`).split('|');
}
const snap = () => page.evaluate(() => ({
  url: location.pathname,
  boxMsg: ((document.querySelector('.vezmopay-inline-message')||{}).innerText||'').trim().slice(0,110),
  payMsg: ((document.getElementById('vezmopay-message')||{}).innerText||'').trim().slice(0,110),
  frames: document.querySelectorAll('.vezmopay-inline-container iframe, #vezmopay-container iframe, #vezmopay-frame').length,
  deadSpinner: !!document.querySelector('.vezmopay-inline-loading'),
}));

let id, key;
if (surface === 'box') {
  await page.goto('http://localhost:8080/?add-to-cart=10', { waitUntil: 'domcontentloaded' });
  await page.goto('http://localhost:8080/checkout/', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(4000);
  for (const [s,v] of [['#email','a@b.test'],['#billing-first_name','Ada'],['#billing-last_name','L'],['#billing-address_1','1 St'],['#billing-city','SF'],['#billing-postcode','94105']]) await page.fill(s,v).catch(()=>{});
  await page.selectOption('#billing-state','CA').catch(()=>{});
  await page.waitForTimeout(2500);
  await page.evaluate(() => { const r = document.querySelector('#radio-control-wc-payment-method-options-vezmopay'); if (r && !r.checked) r.click(); });
  await page.waitForTimeout(4000);
  await page.locator('.vezmopay-inline-pay').first().click();
} else {
  [id, key] = await seedOrder(surface === 'payform' ? 'failed' : 'pending');
  const q = surface === 'payform' ? `?pay_for_order=true&key=${key}` : `?key=${key}`;
  await page.goto(`http://localhost:8080/checkout/order-pay/${id}/${q}`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(3500);
  console.log(tag, 'on load:', JSON.stringify(await snap()));
  if (surface === 'payform') {
    await page.locator('#place_order, button[name="woocommerce_pay"]').first().click().catch(async () => {
      await page.evaluate(() => { const f = document.querySelector('form#order_review'); if (f) f.submit(); });
    });
    await page.waitForTimeout(5000);
    console.log(tag, 'after PAY FOR ORDER →', new URL(page.url()).pathname);
  }
  await page.locator('#vezmopay-pay').click().catch(()=>{});
}
await page.waitForURL(/order-received|order-pay/, { timeout: parseInt(waitMs,10) }).catch(()=>{});
await page.waitForTimeout(3000);
console.log(tag, 'end:', JSON.stringify(await snap()));
console.log(tag, 'order:', wp(`$ids = wc_get_orders(array("limit"=>1,"orderby"=>"ID","order"=>"DESC","return"=>"ids")); $o=wc_get_order($ids[0]); $out="#".$o->get_id()." ".$o->get_status()." pay=".$o->get_meta("_vezmopay_payment_id"); echo $out;`));
if (trace.length) { console.log(tag, 'trace:', trace.slice(-3).join(' | ')); }
await browser.close();
