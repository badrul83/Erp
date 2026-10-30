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
for (const name of ['normalizeSinglePacketRow','configuredUnitPrice','activeUnits','linkedPackNumber','packageWeightGrams','livePackageWeightGrams','isKgUnit','isGramUnit','isWeightStockUnit','volumeUnitMlFactor','isVolumeStockUnit','removeProductVolumeSuffix','removeCategorySuffix','liveProductRow','buildProductPayload']) vm.runInContext(extract(name), context);
assert.equal(context.configuredUnitPrice({PurchasePrice:0,UnitPrice:99},'purchase'),0);
for (const outer of ['Carton','Pack']) for (const [base, perPack, grams] of [['Piece',12,0],['g',10,10],['kg',0.01,10],['mL',250,0]]) {
  let row={SupplierName:'Supplier',stockUnit:base,cartonUnit:outer,perCarton:outer==='Pack'&&grams>0?1:24,perPack,packGrams:grams,purchase:100,sale:137,name:'Test',category:'Food'};
  let product=context.buildProductPayload(row);
  product.ProductID='P1';
  const loaded=context.liveProductRow(product,{});
  assert.equal(loaded.perCarton,row.perCarton,base+' package count');
  assert.equal(loaded.perPack,perPack,base+' packet conversion');
  assert.equal(loaded.purchase,100);
  assert.equal(loaded.sale,137);
  context.rows=[{_product:product}]; context.editing=0;
  const saved=context.buildProductPayload({...loaded,SupplierName:'Supplier'});
  assert.equal(JSON.stringify(saved.Units),JSON.stringify(product.Units),base+' save round trip');
  context.editing=-1;
}
const broken=context.normalizeSinglePacketRow({stockUnit:'g',cartonUnit:'Pack',packGrams:10,perCarton:3.74,perPack:10,purchase:13.99,sale:13.99});
assert.equal(broken.perCarton,1);assert.equal(broken.purchase,3.74);assert.equal(broken.sale,3.74);assert.equal(broken.perPack,10);
// A package that is also the base unit is exactly one stock Pack.
context.editing=-1;
let single=context.buildProductPayload({SupplierName:'Supplier',stockUnit:'Pack',cartonUnit:'Pack',perCarton:1,perPack:1,packGrams:10,purchase:3.74,sale:3.74,name:'Dall Vaza',category:'Food'});
single.ProductID='P2';
const singleRow=context.liveProductRow(single,{});
assert.equal(singleRow.perCarton,1);
assert.equal(singleRow.perPack,1);
assert.equal(singleRow.purchase,3.74);
context.rows=[{_product:single}];context.editing=0;
assert.equal(context.buildProductPayload({...singleRow,SupplierName:'Supplier'}).Units[0].SalePrice,3.74);
const fields={};
context.$=id=>fields[id]||(fields[id]={value:'',textContent:''});
context.packageUnitText=String;context.linkedPriceNumber=n=>String(Number(Number(n).toFixed(6)));
context.linkedPriceSource={purchase:'pack',sale:'pack'};context.linkedQuantitySource='package';
fields['#stock-unit']={value:'Pack'};fields['#carton-unit']={value:'Pack'};
fields['#qty-carton']={value:'3.74'};fields['#qty-pack']={value:'1'};
fields['#pack-purchase-price']={value:'3.74'};fields['#pack-sale-price']={value:'3.74'};
vm.runInContext(extract('syncLinkedPacking'),context);context.syncLinkedPacking();
assert.equal(fields['#qty-carton'].value,'1');assert.equal(fields['#qty-carton'].readOnly,true);
assert.equal(fields['#purchase-price'].value,'3.74');assert.equal(fields['#sale-price'].value,'3.74');
fields['#stock-unit'].value='g';fields['#pack-grams']={value:'10'};fields['#qty-carton'].value='3.74';fields['#qty-pack'].value='10';
context.linkedPriceSource={purchase:'package',sale:'package'};
fields['#purchase-price'].value='13.99';fields['#sale-price'].value='13.99';
context.syncLinkedPacking();
assert.equal(fields['#qty-carton'].value,'1');assert.equal(fields['#qty-pack'].value,'10');
assert.equal(fields['#purchase-price'].value,'3.74');assert.equal(fields['#pack-purchase-price'].value,'3.74');
context.syncLinkedPacking();assert.equal(fields['#purchase-price'].value,'3.74');
context.editing=-1;
const noWeight=context.buildProductPayload({SupplierName:'Supplier',stockUnit:'Pack',cartonUnit:'Pack',perCarton:1,perPack:1,packGrams:0,weightNotApplicable:true,purchase:3.73,sale:3.73,name:'Motor Vaza',category:'Food'});
assert.equal(noWeight.PackageSizeGrams,0);
const noWeightRow=context.liveProductRow({...noWeight,ProductID:'NA'},{});
assert.equal(noWeightRow.weightNotApplicable,true);assert.equal(noWeightRow.packGrams,0);assert.equal(noWeightRow.purchase,3.73);
assert.equal(context.livePackageWeightGrams({PackageSizeGrams:0}, {}, [{UnitName:'Pack',ConversionToBase:10}], 'g',1),0);
assert.ok(html.includes('value="0" data-i18n="packetWeightNA"'));
// Direct units must not create a Pack or carton unit.
for(const base of ['Piece','Litre','Kg']){
 context.editing=-1;
 const payload=context.buildProductPayload({SupplierName:'Supplier',stockUnit:base,cartonUnit:base==='Piece'?'Piece':'Unit',directUnit:true,perCarton:1,perPack:1,packGrams:0,weightNotApplicable:true,purchase:3,sale:5,name:'Direct',category:'Other'});
 assert.equal(payload.Units.length,1);assert.equal(payload.Units[0].UnitName,base);assert.equal(payload.Units[0].ConversionToBase,1);
 assert.equal(payload.Units[0].PurchasePrice*100,300);assert.equal(payload.Units[0].SalePrice*100,500);
 const read=context.liveProductRow({...payload,ProductID:'direct'},{});
 assert.equal(read.cartonUnit,base==='Piece'?'Piece':'Unit');assert.equal(read.purchase,3);assert.equal(read.sale,5);
}
for (const script of html.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/gi)) new vm.Script(script[1]);
console.log('PASS: separate prices, zero price, packet conversions, four unit round trips, inline script syntax');
