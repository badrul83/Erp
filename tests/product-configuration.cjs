const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const html = fs.readFileSync('product-configuration.html', 'utf8');
function extract(name) {
  const start = html.indexOf('function ' + name + '(');
  const brace = html.indexOf('{', start);
  let depth = 1, end = brace + 1;
  while (depth && end < html.length) {
    if (html[end] === '{') depth++;
    if (html[end] === '}') depth--;
    end++;
  }
  return html.slice(start, end);
}
const context = vm.createContext({agreements:[{Company:'Supplier',AgreementID:'S1'}], editing:-1, rows:[], gifts:[],lang:'en', num:String,description:r=>r.name});
for (const name of ['configuredUnitPrice','activeUnits','linkedPackNumber','packageWeightGrams','livePackageWeightGrams','isKgUnit','isGramUnit','isWeightStockUnit','volumeUnitMlFactor','isVolumeStockUnit','removeProductVolumeSuffix','removeCategorySuffix','liveProductRow','buildProductPayload']) vm.runInContext(extract(name), context);
assert.equal(context.configuredUnitPrice({PurchasePrice:0,UnitPrice:99},'purchase'),0);
for (const outer of ['Carton','Pack']) for (const [base, perPack, grams] of [['Piece',12,0],['g',10,10],['kg',0.01,10],['mL',250,0]]) {
  let row={SupplierName:'Supplier',stockUnit:base,cartonUnit:outer,perCarton:24,perPack,packGrams:grams,purchase:100,sale:137,name:'Test',category:'Food'};
  let product=context.buildProductPayload(row);
  product.ProductID='P1';
  const loaded=context.liveProductRow(product,{});
  assert.equal(loaded.perCarton,24,base+' package count');
  assert.equal(loaded.perPack,perPack,base+' packet conversion');
  assert.equal(loaded.purchase,100);
  assert.equal(loaded.sale,137);
  context.rows=[{_product:product}]; context.editing=0;
  const saved=context.buildProductPayload({...loaded,SupplierName:'Supplier'});
  assert.equal(JSON.stringify(saved.Units),JSON.stringify(product.Units),base+' save round trip');
  context.editing=-1;
}
for (const script of html.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/gi)) new vm.Script(script[1]);
console.log('PASS: separate prices, zero price, packet conversions, four unit round trips, inline script syntax');
