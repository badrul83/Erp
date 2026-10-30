const fs=require('fs'),vm=require('vm'),assert=require('assert/strict');
const source=fs.readFileSync('JavaScript.html','utf8');
const context=vm.createContext({txt:v=>String(v??''),productMasterPackageWeight:()=> '10 g'});
for(const name of ['invoiceOptionalValue','invoicePackageWeight','invoiceUnitLabel']){
 const start=source.indexOf('function '+name+'('),brace=source.indexOf('{',start);let depth=1,end=brace+1;
 while(depth){if(source[end]==='{')depth++;if(source[end]==='}')depth--;end++;}
 vm.runInContext(source.slice(start,end),context);
}
for(const value of ['N/A','NA','n/a','Not applicable','N/A — Not applicable'])assert.equal(context.invoiceOptionalValue(value),'');
assert.equal(context.invoiceOptionalValue('10 g'),'10 g');
assert.equal(context.invoiceOptionalValue('Standard'),'Standard');
assert.equal(context.invoicePackageWeight({PackageSizeGrams:0},'10 g'),'');
assert.equal(context.invoicePackageWeight({PackageSizeGrams:10},'10 g'),'10 g');
assert.equal(context.invoiceUnitLabel('N/A',true),'');
assert.equal(context.invoiceUnitLabel('Pack',true),'Pack');
for(const match of source.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/gi))new vm.Script(match[1]);
console.log('PASS: invoice hides N/A and stale weight; real sizes and units remain visible; script syntax');
