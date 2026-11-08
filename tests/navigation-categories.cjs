const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync('JavaScript.html', 'utf8');
const catalog = source.slice(source.indexOf('  var reportCatalog='), source.indexOf('  function workspaceModuleNavigationMarkup('));
const navigation = source.slice(source.indexOf('  function reportNavigation('), source.indexOf("  document.addEventListener('click',function(event){var moduleLink="));
const commerce = source.slice(source.indexOf('  function commerceNavigationMarkup('), source.indexOf('  function renderWorkspaceTopbarNavigation('));
const moduleNav = source.slice(source.indexOf('  function workspaceModuleNavigationMarkup('), source.indexOf('  function commerceNavigationMarkup('));
assert.ok(catalog && navigation && commerce && moduleNav, 'Navigation implementations are available');

const host = { innerHTML: '', hidden: false, classList: { add: () => { host.hidden = true; } } };
const workspace = { hidden: true, classList: { remove: () => { workspace.hidden = false; } } };
let role = 'ADMIN';
let permissions = new Set();
const ctx = {
  document: { getElementById: id => id === 'workspace-topbar-navigation' ? workspace : host },
  state: { reportContext: '' },
  renderWorkspaceTopbarNavigation: view => { workspace.rendered = view; },
  esc: value => String(value).replace(/[&<>"']/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[character])),
  userRole: () => role,
  hasPermission: key => role === 'ADMIN' || permissions.has(key),
  canView: view => role === 'ADMIN' || (view === 'reports' && permissions.has('reports'))
};
vm.createContext(ctx);
vm.runInContext(catalog + navigation + moduleNav + commerce, ctx);

const allKeys = Array.from(ctx.reportCatalog, row => row[0]);
const groupedKeys = Array.from(ctx.reportGroups).flatMap(group => Array.from(ctx.reportItemsForGroup(group), row => row[0]));
assert.deepEqual(groupedKeys.slice().sort(), allKeys.slice().sort(), 'Every report appears in exactly one category for admin');
ctx.reportNavigation('returns');
assert.equal((host.innerHTML.match(/class="commerce-menu-group report-menu-group"/g) || []).length, 4);
for (const key of allKeys) assert.equal((host.innerHTML.match(new RegExp('data-report-key="' + key + '"', 'g')) || []).length, 1, key);
assert.match(host.innerHTML, /data-report-key="returns" aria-current="page"/, 'Current report retains accessibility state');
ctx.state.reportContext = 'purchase';
ctx.reportNavigation('supplies');
assert.equal(host.hidden, true, 'Purchase report hides the separate report navigation');
assert.equal(workspace.hidden, false, 'Purchase report keeps its Purchase navigation');
assert.equal(workspace.rendered, 'purchases');
ctx.state.reportContext = '';
ctx.state.reportContext = 'sales';
host.hidden = false; workspace.hidden = true;
ctx.reportNavigation('sales');
assert.equal(host.hidden, true, 'Sales report hides the separate report navigation');
assert.equal(workspace.rendered, 'sales', 'Sales reports retain Sales categories');
ctx.state.reportContext = '';

role = 'STANDARD';
permissions = new Set(['reports']);
assert.equal(ctx.canOpenReport('sales'), true);
assert.equal(ctx.canOpenReport('goods-receipt'), false);
assert.equal(ctx.canOpenReport('trial-balance'), false);
assert.equal(ctx.canOpenReport('dealer-deposits'), false);
ctx.reportNavigation('');
assert.doesNotMatch(host.innerHTML, /data-report-key="(?:goods-receipt|trial-balance|dealer-deposits)"/, 'Unavailable reports are not linked');
permissions = new Set(['reports', 'accounting', 'goods_receipts', 'dealers']);
assert.equal(ctx.canOpenReport('goods-receipt'), true);
assert.equal(ctx.canOpenReport('dealer-ledger'), true);
assert.equal(ctx.canOpenReport('supplier-ledger'), false);
const purchase = ctx.commerceNavigationMarkup('purchases', view => view === 'reports' || view === 'purchases');
assert.match(purchase, /data-commerce-report="goods-receipt"/);
assert.doesNotMatch(purchase, /data-topbar-view="goods-receipts"/, 'Routes keep module permission checks');
const purchaseAll = ctx.commerceNavigationMarkup('purchases', () => true);
assert.match(purchaseAll, /id="purchase-order-menu"[\s\S]*<summary>Purchase Order<\/summary>[\s\S]*data-topbar-view="goods-receipts"/, 'Receipt is grouped under Purchase Order');
assert.doesNotMatch(purchaseAll, /<summary>Operations<\/summary>/, 'Purchase header uses the reference categories');
const grnOnly = ctx.commerceNavigationMarkup('goods-receipts', view => view === 'goods-receipts');
assert.match(grnOnly, /id="purchase-order-menu"/, 'Receipt-only users have a Purchase Order menu for receipt tabs');
assert.doesNotMatch(grnOnly, /data-topbar-view="purchases"/, 'Receipt-only users do not gain Purchase Orders access');

role = 'ADMIN';
for (const view of ['agreements','dealer-sales-management','easy-accounts','payments','product-config']) {
  const markup = ctx.workspaceModuleNavigationMarkup(view, () => true);
  assert.match(markup, /<details class="commerce-menu-group">/, 'Header categories: ' + view);
  assert.match(markup, /data-topbar-view=|data-commerce-report=/, 'Working header routes: ' + view);
}
const index = fs.readFileSync('Index.html', 'utf8');
const drawer = index.slice(index.indexOf('<nav class="main-nav">'), index.indexOf('</nav>', index.indexOf('<nav class="main-nav">')));
assert.doesNotMatch(drawer, /<details\b|<summary\b/, 'App drawer contains direct links, not submenu groups');
for (const view of ['dashboard','purchases','agreements','product-config','products','sales','hr','employee-profile','reports','easy-accounts','payments','accounting','settings']) {
  assert.equal((index.match(new RegExp('class="[^"]*nav-item[^"]*"[^>]*data-view="' + view + '"', 'g')) || []).length, 1, 'Drawer route: ' + view);
}
const views = Array.from(drawer.matchAll(/data-view="([^"]+)"/g), match => match[1]);
const rendererTable = source.slice(source.indexOf('({dashboard:renderDashboard,'), source.indexOf('}[view] || renderDashboard)'));
for (const view of views) {
  assert.ok(rendererTable.includes(view + ':') || rendererTable.includes("'" + view + "':"), 'Every drawer item has a page renderer: ' + view);
}
const accessCode = source.slice(source.indexOf('  var viewPermissions='), source.indexOf('  function applyAccessNavigation()'));
const access = { state: { user: { Role: 'ADMIN', PermissionKeys: [] } } };
vm.createContext(access);
vm.runInContext(accessCode, access);
assert.equal(access.canView('accounting'), true, 'Admin can open accounting directly');
assert.equal(access.hrDrawerView(), 'hr', 'Admin opens HR from the HR app');
assert.equal(access.purchaseDrawerView(), 'purchases', 'Admin opens Purchase Orders from the Purchase app');
assert.equal(access.salesDrawerView(), 'sales', 'Admin opens the Sales register from the Sales app');
assert.equal(access.canView('dealer-portal'), false, 'Admin does not impersonate a dealer');
access.state.user = { Role: 'STANDARD', PermissionKeys: ['dealer_sales_management'] };
assert.equal(access.hrDrawerView(), 'dealer-sales-management', 'SR-only users keep access through the HR app');
assert.equal(access.canView('hr'), false, 'SR-only users do not gain full HR access');
access.state.user = { Role: 'STANDARD', PermissionKeys: ['goods_receipts'] };
assert.equal(access.purchaseDrawerView(), 'goods-receipts', 'Receipt-only users enter via the Purchase app');
assert.equal(access.canView('purchases'), false, 'Receipt-only users do not gain Purchase Orders access');
access.state.user = { Role: 'DEALER', PermissionKeys: ['dealer_orders'] };
assert.equal(access.canView('dealer-portal'), true, 'Dealer can open their own portal directly');
assert.equal(access.salesDrawerView(), 'dealer-portal', 'Dealer-only users retain access through Sales');
assert.equal(access.canView('accounting'), false, 'Dealer cannot open accounting');
access.state.user = { Role: 'EMPLOYEE', PermissionKeys: [] };
assert.equal(access.canView('employee-profile'), true, 'Employee can open their profile directly');
assert.equal(access.canView('hr'), false, 'Employee cannot open administrator HR');
assert.doesNotMatch(drawer, /data-view="dealer-sales-management"/, 'SR Management is not a separate app');
assert.doesNotMatch(drawer, /data-view="goods-receipts"/, 'Goods Receipt is not a separate app');
for (const view of ['dealers','dealer-incentive','dealer-portal','returns']) assert.doesNotMatch(drawer, new RegExp('data-view="' + view + '"'), view + ' is grouped under Sales');
access.state.user = { Role: 'STANDARD', PermissionKeys: ['returns'] };
assert.equal(access.salesDrawerView(), 'returns', 'Returns-only users retain their Sales entry');
const sales = ctx.commerceNavigationMarkup('sales', () => true);
for (const view of ['sales','returns','dealers','dealer-incentive','dealer-portal']) assert.match(sales, new RegExp('data-topbar-view="' + view + '"'), 'Sales contains ' + view);
for (const report of ['sales','dealers','returns','dealer-ledger','dealer-deposits']) assert.match(sales, new RegExp('data-commerce-report="' + report + '"'), 'Sales contains report ' + report);
assert.match(sales, /id="sales-orders-menu"[\s\S]*<summary>Orders<\/summary>/);
assert.match(sales, /id="sales-customers-menu"[\s\S]*<summary>Customers<\/summary>/);
assert.match(fs.readFileSync('HR.html','utf8'), /\['sr-management','SR Management'\]/, 'SR Management appears in the HR Employees menu');
role = 'ADMIN';
assert.doesNotMatch(ctx.workspaceModuleNavigationMarkup('dealers', () => true), /SR Management/, 'SR Management is not a customer category');
assert.match(ctx.workspaceModuleNavigationMarkup('dealer-sales-management', () => true), /Human Resources[\s\S]*Employees[\s\S]*SR Management/, 'SR-only view is labeled under HR');
assert.match(ctx.workspaceModuleNavigationMarkup('agreements', () => true), /Suppliers[\s\S]*Supplier Register[\s\S]*Purchasing[\s\S]*Purchase Orders[\s\S]*Goods Receipt/, 'Supplier and purchasing links use their own categories');
console.log('PASS categorized reports, permission filtering, and existing drawer routes');
