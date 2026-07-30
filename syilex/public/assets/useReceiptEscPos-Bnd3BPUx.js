import{A as Q,K as V}from"./vue-core-Dr_IIeMI.js";import{u as kt}from"./useFormatters-EzIEeXJw.js";import{u as yt}from"./useReceiptPdf-B5mf9_oV.js";import{a as Ot}from"./index-BOqUA6y4.js";function gt(t=null,i={}){const o=(c,f)=>{const b=String(c??"").trim();return b!==""?b:f??""};return{name:o(t==null?void 0:t.store_name,i.name||"POSIP"),address:o(t==null?void 0:t.store_address,i.address||""),phone:o(t==null?void 0:t.store_phone,i.phone||""),email:o(t==null?void 0:t.store_email,i.email||""),npwp:o(t==null?void 0:t.store_npwp,i.npwp||""),receiptFooter:o(t==null?void 0:t.receipt_footer,i.receiptFooter||"")}}function Gt(t,i={}){return!t||typeof t!="object"?gt(null,i):{name:String(t.name||i.name||"POSIP").trim()||"POSIP",address:String(t.address??i.address??""),phone:String(t.phone??i.phone??""),email:String(t.email??i.email??""),npwp:String(t.npwp??i.npwp??""),receiptFooter:String(t.receipt_footer??t.receiptFooter??i.receiptFooter??"")}}const it="posip-thermal-printer",lt=new Set(["bluetooth","serial","usb"]);function Nt(t){if(!t||typeof t!="object")return null;const i=t.kind;if(!lt.has(i))return null;const o={kind:i},c=t.terminalUlid,f=t.label;return typeof c=="string"&&c.trim()&&(o.terminalUlid=c.trim()),typeof f=="string"&&f.trim()&&(o.label=f.trim()),o}function j(){try{const t=localStorage.getItem(it);return t?Nt(JSON.parse(t)):null}catch{return null}}function Lt(t){if(!(t!=null&&t.kind)||!lt.has(t.kind))throw new Error("Invalid printer kind");const i={kind:t.kind};t.terminalUlid&&(i.terminalUlid=t.terminalUlid),t.label&&(i.label=t.label),localStorage.setItem(it,JSON.stringify(i))}function Bt(){try{localStorage.removeItem(it)}catch{}}function Ht(t){const i=j();return i?!t||!i.terminalUlid?!0:i.terminalUlid===t:!1}function St(t){if(typeof t!="string"||!t.length)return new Uint8Array(0);const i=t.trim();if(!i.length)return new Uint8Array(0);const o=atob(i),c=new Uint8Array(o.length);for(let f=0;f<o.length;f++)c[f]=o.charCodeAt(f);return c}const Tt=[27,112,0,25,25],Et=[29,86,1];let N=null;const Ft=[6384,65504,65280,"49535343-fe7d-4ae5-8fa9-9fafd205e455","e7810a71-73ae-499d-8c15-faa9aef0c3f2","6e400001-b5a3-f393-e0a9-e50e24dcca9e"];function rt(t=typeof navigator<"u"?navigator:{}){return t}function Z(){return N}function ot(t){const i=rt(t);return{bluetooth:!!i.bluetooth,serial:!!i.serial,usb:!!i.usb}}function ft(t){const i=ot(t);return i.bluetooth||i.serial||i.usb}function Dt(t,i){i==null||i(),N&&(N.disconnect().catch(()=>{}),N=null)}async function mt(t){const o=await(await t.gatt.connect()).getPrimaryServices();let c=null;for(const x of o){const O=await x.getCharacteristics();for(const s of O)if(s.properties.write||s.properties.writeWithoutResponse){c=s;break}if(c)break}if(!c)throw new Error("Karakteristik tulis printer Bluetooth tidak ditemukan.");const f=c.properties.writeWithoutResponse&&!c.properties.write,b=c;return{kind:"bluetooth",label:t.name||"Printer Bluetooth",async write(x){for(let s=0;s<x.length;s+=180){const E=x.slice(s,s+180);f&&b.writeValueWithoutResponse?await b.writeValueWithoutResponse(E):await b.writeValue(E),await new Promise(F=>setTimeout(F,18))}},async disconnect(){var x;try{(x=t.gatt)==null||x.disconnect()}catch{}}}}function _t(t){return{kind:"serial",label:"Printer USB (Serial)",async write(i){const o=t.writable.getWriter();try{await o.write(i)}finally{o.releaseLock()}},async disconnect(){try{await t.close()}catch{}}}}async function dt(t){await t.open(),t.configuration===null&&await t.selectConfiguration(1);let i=0,o=1;for(const c of t.configuration.interfaces)for(const f of c.alternates)if(f.interfaceClass===7||f.interfaceClass===255){i=c.interfaceNumber;const b=f.endpoints.find(x=>x.direction==="out");b&&(o=b.endpointNumber)}return await t.claimInterface(i),{kind:"usb",label:t.productName||"Printer USB",async write(c){for(let b=0;b<c.length;b+=4096)await t.transferOut(o,c.slice(b,b+4096))},async disconnect(){try{await t.close()}catch{}}}}async function Rt(t){if(!t.bluetooth)throw new Error("Browser tidak mendukung Web Bluetooth (pakai Chrome/Edge).");const i=await t.bluetooth.requestDevice({acceptAllDevices:!0,optionalServices:Ft});return N=await mt(i),N}async function wt(t){if(!t.serial)throw new Error("Browser tidak mendukung Web Serial (pakai Chrome/Edge desktop).");const i=await t.serial.requestPort();return await i.open({baudRate:9600}),N=_t(i),N}async function Pt(t){if(!t.usb)throw new Error("Browser tidak mendukung WebUSB.");const i=await t.usb.requestDevice({filters:[{classCode:7},{classCode:255}]});return N=await dt(i),N}async function At(t,i){const o=rt(i);return t==="bluetooth"?Rt(o):t==="serial"?wt(o):Pt(o)}async function ht(t,i){var c,f,b;if(N)return N;const o=rt(i);try{if(t==="serial"&&((c=o.serial)!=null&&c.getPorts)){const x=await o.serial.getPorts();if(x.length){try{await x[0].open({baudRate:9600})}catch{}return N=_t(x[0]),N}}if(t==="bluetooth"&&((f=o.bluetooth)!=null&&f.getDevices)){const x=await o.bluetooth.getDevices();if(x.length)return N=await mt(x[0]),N}if(t==="usb"&&((b=o.usb)!=null&&b.getDevices)){const x=await o.usb.getDevices();if(x.length)return N=await dt(x[0]),N}}catch{}return null}async function $t(t,i={}){const{writeFn:o,reconnectFn:c}=i;if(!t)return{ok:!1,error:"Data cetak kosong"};let f;try{f=St(t)}catch{return{ok:!1,error:"Data base64 tidak valid"}}if(!f.length)return{ok:!1,error:"Payload ESC/POS kosong"};const b=j();let x=Z();if(x||(x=await(c||(()=>ht((b==null?void 0:b.kind)??null)))()),x)try{return await(o||(s=>x.write(s)))(f),{ok:!0}}catch(O){return{ok:!1,error:(O==null?void 0:O.message)||"Gagal mengirim ke printer"}}return b!=null&&b.kind?{ok:!1,needPicker:!0,error:"Printer perlu disambungkan ulang"}:{ok:!1,needPicker:!0,error:"Printer thermal belum dipasangkan"}}async function It(){return!!(Z()||j())}function Ut(){const t=Q(Z()),i=Q(null),o=V(()=>ft()),c=V(()=>ot()),f=V(()=>{var T,L;return((T=t.value)==null?void 0:T.label)||((L=j())==null?void 0:L.label)||null}),b=V(()=>!!t.value);function x(){t.value=Z()}async function O(T,{terminalUlid:L,label:g}={}){i.value=null;try{const w=await At(T);return Lt({kind:T,terminalUlid:L,label:g||w.label}),t.value=w,w}catch(w){throw i.value=(w==null?void 0:w.message)||"Gagal menghubungkan printer",w}}async function s(){i.value=null;const T=j(),L=await ht((T==null?void 0:T.kind)??null);return t.value=L,L}function E(){Dt(j(),Bt),t.value=null,i.value=null}async function F(T){const L=t.value||await s();if(!L)throw new Error("Printer belum dipasangkan");await L.write(T)}return{connection:t,lastError:i,supported:o,support:c,printerLabel:f,isConnected:b,pick:O,reconnect:s,forget:E,write:F,syncConnection:x}}function qt(){const t=Ut(),i=Q(!1),o=Q(!1),c=Q(null),f=V(()=>ft()),b=V(()=>ot()),x=V(()=>{var g;return t.printerLabel.value||((g=j())==null?void 0:g.label)||null});async function O(){const g=await It();return i.value=g,g}function s(){var g;return!!(Z()||(g=j())!=null&&g.kind)}async function E(g,w){return t.pick(g,w)}async function F(){return t.reconnect()}function T(){t.forget()}async function L(g,w={}){o.value=!0,c.value=null;try{const M=await $t(g,{writeFn:et=>t.write(et),reconnectFn:()=>t.reconnect()});return M.ok||(c.value=M.error||"Cetak gagal"),{success:M.ok,needPicker:M.needPicker||!1,message:M.error}}finally{o.value=!1}}return{isAvailable:i,busy:o,error:c,supported:f,support:b,printerLabel:x,checkStatus:O,isReadyToThermal:s,pick:E,reconnect:F,forget:T,printRaw:L,transport:t}}function Kt(t,i,o,c){const f=[];if(f.push(o("PENJUALAN",`${t.jumlah_transaksi||0} trx`,c)),f.push(o("Penjualan Kotor",i(t.penjualan_kotor),c)),Number(t.diskon_item)>0){f.push(o("Diskon Item","-"+i(t.diskon_item),c));for(let b=1;b<=5;b++){const x=Number(t[`diskon_line_${b}`]||0);if(x>0){const O=b===5?" (Manual)":"";f.push(o(`  Line ${b}${O}`,"-"+i(x),c))}}}else f.push(o("Diskon Item",i(0),c));return Number(t.diskon_nota)>0?(f.push(o("Diskon Nota","-"+i(t.diskon_nota),c)),Number(t.diskon_nota_l1)>0&&f.push(o("  Tipe Customer (L1)","-"+i(t.diskon_nota_l1),c)),Number(t.diskon_nota_l2)>0&&f.push(o("  Kategori Customer (L2)","-"+i(t.diskon_nota_l2),c)),Number(t.diskon_nota_l3)>0&&f.push(o("  Manual Kasir (L3)","-"+i(t.diskon_nota_l3),c))):f.push(o("Diskon Nota",i(0),c)),f.push(o("Penjualan Bersih",i(t.penjualan_bersih),c)),f.push(o("Biaya Kirim",i(t.biaya_kirim),c)),f.push(o("Biaya Lain",i(t.biaya_lain),c)),t.pajak_nama?f.push(o(`Pajak (${t.pajak_nama} ${t.pajak_persen}%)`,i(t.pajak_nominal),c)):f.push(o("Pajak",i(t.pajak_nominal),c)),f.push(o("Pembulatan",i(t.pembulatan),c)),f.push(o("OMZET",i(t.omzet),c)),f}function vt(t=4,i=!1){const o=[];i&&o.push(...Tt);const c=Math.min(Math.max(t,0),10);return c>0&&o.push(27,100,c),o.push(...Et),new Uint8Array(o)}const a={INIT:[27,64],INIT_FEED:[27,64,10],CENTER:[27,97,1],LEFT:[27,97,0],BOLD_ON:[27,69,1],BOLD_OFF:[27,69,0],DOUBLE:[27,33,48],NORMAL:[27,33,0],DRAWER_2:[27,112,0,25,25]};class Y{constructor(){this._parts=[]}cmd(i){return this._parts.push(new Uint8Array(i)),this}text(i){const o=new Uint8Array(i.length);for(let c=0;c<i.length;c++){const f=i.charCodeAt(c);o[c]=f<128?f:63}return this._parts.push(o),this}toBytes(){let i=0;for(const f of this._parts)i+=f.length;const o=new Uint8Array(i);let c=0;for(const f of this._parts)o.set(f,c),c+=f.length;return o}toBase64(){const i=this.toBytes();let o="";for(let c=0;c<i.length;c++)o+=String.fromCharCode(i[c]);return btoa(o)}}function k(t,i){return t.repeat(i)+`
`}function l(t,i,o){const c=i.length,f=o-c-1;return(t.length>f?t.slice(0,f):t+" ".repeat(Math.max(0,f-t.length)))+" "+i}function q(t,i){if(i<=0)return[String(t)];const o=String(t);if(o.length<=i)return[o];const c=(o.match(/^\s*/)||[""])[0],f=Math.max(1,i-c.length),b=o.trim().split(/\s+/).flatMap(s=>{if(s.length<=f)return[s];const E=[];for(let F=0;F<s.length;F+=f)E.push(s.slice(F,F+f));return E}),x=[];let O=c;for(const s of b){const E=O.trim()===""?c+s:O+" "+s;E.length>i&&O.trim()!==""?(x.push(O),O=c+s):O=E}return O.trim()!==""&&x.push(O),x.length?x:[o]}function tt(t,i,o){const c=String(t??""),f=String(i??"");return c.length+1+f.length<=o?[l(c,f,o)]:[...q(c,o),l("",f,o)]}function z(t,i,o=!1){t.cmd(Array.from(vt(i,o)))}function Vt(){const{formatCurrency:t,formatNumber:i,formatQty:o,formatPercent:c,formatDateTime:f}=kt(),b=Ot(),{buildReturPolicyText:x}=yt();function O(n){return n==null?"0":i(Math.abs(Number(n)))}function s(n){if(n==null)return"0";const h=Number(n),e=O(h);return h<0?`-${e}`:e}function E(n){const h=[];return n.kode_internal&&h.push(n.kode_internal),h.push(`SN ${n.serial_number||"-"}`),n.grade&&h.push(n.grade),n.battery_health!==null&&n.battery_health!==void 0&&n.battery_health!==""?h.push(`Bat ${n.battery_health}%${n.battery_condition?" "+n.battery_condition:""}`):n.battery_condition&&h.push(`Bat ${n.battery_condition}`),n.battery_cycle_count!==null&&n.battery_cycle_count!==void 0&&n.battery_cycle_count!==""&&h.push(`Cyc ${n.battery_cycle_count}`),n.account_status&&h.push(n.account_status),{main:h.join(" . "),catatan:n.catatan||""}}function F(n,h,e){if(h!=null&&h.length)for(const p of h){const{main:y,catatan:D}=E(p);for(const r of q("  "+y,e))n.text(r+`
`);if(D)for(const r of q("    Cat: "+D,e))n.text(r+`
`)}}function T(n){const h=[];for(let e=1;e<=5;e++){const p=n[`diskon_${e}_tipe`],y=Number(n[`diskon_${e}_nilai`]||0);p==="none"||y===0||h.push(p==="percent"?c(y):t(y))}return h.join("+")}function L(n,h,e){if(!h||!e)return n;const p=h==="percent"?c(e):s(e);return`${n} (${p})`}function g(n,h,e,p=null){const y=p||b.store;if(n.cmd(a.CENTER),e?n.cmd(a.BOLD_ON).text((y.name||"POSIP")+`
`).cmd(a.BOLD_OFF):n.cmd(a.DOUBLE).text((y.name||"POSIP")+`
`).cmd(a.NORMAL),y.address)for(const D of String(y.address).split(/\r?\n/))D.trim()&&n.text(D+`
`);y.phone&&n.text("Telp: "+y.phone+`
`),y.email&&n.text("Email: "+y.email+`
`),y.npwp&&n.text("NPWP: "+y.npwp+`
`),n.text(k("=",h))}function w(n,h={}){var K,C,$,W,G;const{charWidth:e=42,feedLines:p=4,compact:y=!1,returPolicy:D=null,footer:r=null,openDrawer:_=!1,store:v=null}=h,u=new Y;u.cmd(a.INIT_FEED),g(u,e,y,v),u.cmd(a.LEFT),u.text(l("No",": "+(n.nomor_dokumen||"-"),e)+`
`),u.text(l("Tgl",": "+f(n.tanggal),e)+`
`),(K=n.created_by)!=null&&K.name&&u.text(l("Kasir",": "+n.created_by.name,e)+`
`);const U=(C=n.customer)==null?void 0:C.nama;U&&U!=="Walk-in"&&u.text(l("Cust",": "+U,e)+`
`),u.text(k("-",e));for(const d of n.details||[]){u.text(((($=d.product)==null?void 0:$.nama_produk)||"")+`
`);const B=Number(d.qty||0)*Number(d.harga_satuan||0);if(u.text(l(`  ${o(d.qty)} ${d.unit||""} x ${s(d.harga_satuan)}`,s(B),e)+`
`),Number(d.diskon_total)>0){const R=T(d);u.text(l(`    ${R}`,"-"+s(d.diskon_total),e)+`
`)}F(u,d.serial_units,e)}u.text(k("-",e)),u.text(l("Subtotal",s(n.subtotal),e)+`
`);for(let d=1;d<=3;d++){const B=Number(n[`diskon_nota_${d}_hasil`]||0);if(B>0){const R=n[`_disc_label_${d}`]||n[`diskon_nota_${d}_label`],H=d===3?"Disc Manual":`Disc ${d}`,X=R?L(R,n[`diskon_nota_${d}_tipe`],n[`diskon_nota_${d}_nilai`]):L(H,n[`diskon_nota_${d}_tipe`],n[`diskon_nota_${d}_nilai`]);u.text(l("  "+X,"-"+s(B),e)+`
`)}}if(Number(n.total_diskon)>0&&u.text(l("Total",s(n.total_setelah_diskon),e)+`
`),Number(n.biaya_kirim_hasil)>0){const d=L("Biaya Kirim",n.biaya_kirim_tipe,n.biaya_kirim_nilai);u.text(l(d,s(n.biaya_kirim_hasil),e)+`
`)}if(Number(n.biaya_lain_hasil)>0){const d=L("Biaya Lain",n.biaya_lain_tipe,n.biaya_lain_nilai);u.text(l(d,s(n.biaya_lain_hasil),e)+`
`)}Number(n.pajak_nominal)>0&&(u.text(l("DPP",s(n.dpp),e)+`
`),u.text(l(`${n.pajak_nama||"PPN"} ${n.pajak_persen}%`,s(n.pajak_nominal),e)+`
`)),Number(n.pembulatan)&&u.text(l("Pembulatan",s(n.pembulatan),e)+`
`),u.text(k("-",e)),u.cmd(a.BOLD_ON),u.text(l("GRAND TOTAL",s(n.grand_total),e)+`
`),u.cmd(a.BOLD_OFF),u.text(k("-",e));for(const d of n.payments||[])u.text(l(((W=d.metode_pembayaran)==null?void 0:W.nama_pembayaran)||"",s(d.nominal),e)+`
`),Number(d.biaya_tambahan)>0&&u.text(l("  Biaya",s(d.biaya_tambahan),e)+`
`);Number(n.total_bayar)&&(u.cmd(a.BOLD_ON),u.text(l("Total Bayar",s(n.total_bayar),e)+`
`),u.cmd(a.BOLD_OFF)),Number(n.kembalian)>0&&(u.cmd(a.BOLD_ON),u.text(l("Kembali",s(n.kembalian),e)+`
`),u.cmd(a.BOLD_OFF)),u.text(k("=",e));const P=n.returns||[];if(P.length>0){u.cmd(a.BOLD_ON).text(`RIWAYAT RETUR
`).cmd(a.BOLD_OFF);for(const B of P){u.text(l(B.nomor_dokumen||"","Tunai",e)+`
`),u.text("  "+f(B.tanggal)+`
`);for(const R of B.details||[]){for(const H of tt(`  ${((G=R.product)==null?void 0:G.nama_produk)||""} x${o(R.qty)}`,`@ ${s(R.harga_satuan)}`,e))u.text(H+`
`);F(u,R.serial_units,e)}Number(B.pembulatan)&&u.text(l("  Pembulatan",s(B.pembulatan),e)+`
`),u.cmd(a.BOLD_ON).text(l("  Total Retur",s(B.grand_total),e)+`
`).cmd(a.BOLD_OFF)}u.text(k("-",e)),u.cmd(a.BOLD_ON).text(`RINGKASAN
`).cmd(a.BOLD_OFF),u.text(l("Pembayaran Asli",s(n.grand_total),e)+`
`),(Number(n.biaya_kirim_hasil)>0||Number(n.biaya_lain_hasil)>0)&&(u.text(`Tidak Termasuk Retur:
`),Number(n.biaya_kirim_hasil)>0&&u.text(l("  Biaya Kirim",s(n.biaya_kirim_hasil),e)+`
`),Number(n.biaya_lain_hasil)>0&&u.text(l("  Biaya Lain",s(n.biaya_lain_hasil),e)+`
`));const d=P.reduce((B,R)=>B+Number(R.grand_total||0),0);u.text(l("Total Semua Retur",s(d),e)+`
`),u.cmd(a.BOLD_ON),u.text(l("NILAI BERSIH",s(Number(n.grand_total)-d),e)+`
`),u.cmd(a.BOLD_OFF),u.text(`(Pembayaran - Retur)
`),u.text(k("=",e))}if(n.status==="voided"?(u.cmd(a.CENTER).cmd(a.BOLD_ON),y||u.cmd(a.DOUBLE),u.text(`*** VOID ***
`),u.cmd(a.NORMAL).cmd(a.BOLD_OFF)):P.length>0&&(u.cmd(a.CENTER).cmd(a.BOLD_ON),u.text(`*** RETUR ***
`),u.cmd(a.BOLD_OFF)),D){const d=x(D,n.tanggal);d&&u.cmd(a.CENTER).text(d+`
`)}const S=r||"Terima Kasih!";u.cmd(a.CENTER);for(const d of S.split(`
`))u.text(d+`
`);return n.notes&&u.cmd(a.CENTER).text(n.notes+`
`),z(u,p,_),u.toBase64()}function M(n,h,e={}){var U,P;const{charWidth:p=42,feedLines:y=4,compact:D=!1,store:r=null}=e,_=new Y;_.cmd(a.INIT_FEED),g(_,p,D,r),_.cmd(a.CENTER).cmd(a.BOLD_ON).text(`STRUK RETUR
`).cmd(a.BOLD_OFF).cmd(a.LEFT),_.text(k("=",p)),_.text(l("No Retur",": "+(n.nomor_dokumen||"-"),p)+`
`),_.text(l("No Nota",": "+((h==null?void 0:h.nomor_dokumen)||"-"),p)+`
`),_.text(l("Tgl",": "+f(n.tanggal||new Date),p)+`
`),(U=n.created_by)!=null&&U.name&&_.text(l("Kasir",": "+n.created_by.name,p)+`
`),_.text(k("-",p));for(const S of n.details||[]){const K=((P=S.product)==null?void 0:P.nama_produk)||"",C=S.qty||0,$=S.harga_satuan||S.harga_per_base||0,W=Number(C)*Number($);_.text(K+`
`),_.text(l(`  ${o(C)} x ${s($)}`,s(W),p)+`
`),F(_,S.serial_units,p)}_.text(k("-",p));const v=Number(n.subtotal||0),u=Number(n.pembulatan||0);return v&&_.text(l("Subtotal",s(v),p)+`
`),u&&_.text(l("Pembulatan",s(u),p)+`
`),_.cmd(a.BOLD_ON),_.text(l("TOTAL RETUR",s(n.grand_total),p)+`
`),_.cmd(a.BOLD_OFF),_.text(k("-",p)),_.text(l("Metode Refund","Tunai",p)+`
`),_.text(k("=",p)),_.cmd(a.CENTER).text(`Terima Kasih
`),z(_,y),_.toBase64()}function et(n,h={}){const{charWidth:e=42,feedLines:p=4,compact:y=!1,store:D=null}=h,r=new Y;r.cmd(a.INIT_FEED),g(r,e,y,D);const v={kas_masuk:"KAS MASUK",kas_keluar:"KAS KELUAR",setor_awal:"SETOR AWAL"}[n.tipe]||"TRANSAKSI KAS";if(r.cmd(a.CENTER).cmd(a.BOLD_ON).text(v+`
`).cmd(a.BOLD_OFF).cmd(a.LEFT),r.text(k("=",e)),r.text(l("Terminal",": "+(n.terminal||"-"),e)+`
`),r.text(l("Kasir",": "+(n.kasir||"-"),e)+`
`),r.text(l("Tanggal",": "+(n.date||"-"),e)+`
`),r.text(k("-",e)),r.cmd(a.BOLD_ON),r.text(l("Nominal",s(n.nominal),e)+`
`),r.cmd(a.BOLD_OFF),n.keterangan)for(const u of q("Ket: "+n.keterangan,e))r.text(u+`
`);return r.text(k("=",e)),z(r,p),r.toBase64()}function bt(n,h={}){var st,ct,ut;const{charWidth:e=42,feedLines:p=4,compact:y=!1,store:D=null}=h,r=new Y,_=n.shift||{},v=n.penjualan||{},u=n.payment_breakdown||[],U=n.void||{},P=n.retur||{},S=n.kas||{},K=n.ringkasan||{};r.cmd(a.INIT_FEED),g(r,e,y,D),r.cmd(a.CENTER).cmd(a.BOLD_ON).text(`LAPORAN SHIFT
`).cmd(a.BOLD_OFF),_.ulid&&r.text(_.ulid+`
`),r.cmd(a.LEFT),r.text(k("=",e)),r.text(l("Terminal",": "+(((st=_.terminal)==null?void 0:st.kode_terminal)||"-"),e)+`
`),r.text(l("Kasir",": "+(((ct=_.user)==null?void 0:ct.name)||"-"),e)+`
`),r.text(l("Mulai",": "+(_.started_at?f(_.started_at):"-"),e)+`
`),r.text(l("Selesai",": "+(_.ended_at?f(_.ended_at):"-"),e)+`
`);let C="Masih Aktif";_.ended_at&&(C=_.ended_by_force?`Ditutup Paksa oleh ${((ut=_.forced_by_user)==null?void 0:ut.name)||"Admin"}`:"Ditutup Normal");for(const m of q("Status: "+C,e))r.text(m+`
`);r.text(k("-",e));const $=Kt(v,s,l,e),W=$.length?$[$.length-1]:null,G=$.slice(0,-1);if(G.length){r.cmd(a.BOLD_ON).text(G[0]+`
`).cmd(a.BOLD_OFF);for(let m=1;m<G.length;m++)r.text(G[m]+`
`)}const d=u.reduce((m,A)=>m+Number(A.biaya_tambahan||0),0);d>0&&r.text(l("Biaya Pembayaran",s(d),e)+`
`),W&&r.cmd(a.BOLD_ON).text(W+`
`).cmd(a.BOLD_OFF),r.text(k("-",e));const B=n.serial_units_sold||[];if(B.length){r.cmd(a.BOLD_ON),r.text(l("UNIT SERIAL TERJUAL",`${B.length} unit`,e)+`
`),r.cmd(a.BOLD_OFF);for(const m of B){for(const J of tt(m.product||"-",s(m.harga),e))r.text(J+`
`);const A=`  ${m.kode_internal||"SN "+(m.serial_number||"-")} | ${m.nomor_dokumen||"-"}`;for(const J of q(A,e))r.text(J+`
`);const I=[];if(m.kode_internal&&m.serial_number&&I.push(`SN ${m.serial_number}`),m.grade&&I.push(`Grade ${m.grade}`),m.battery_health!==null&&m.battery_health!==void 0&&I.push(`Bat ${m.battery_health}%`),m.battery_cycle_count!==null&&m.battery_cycle_count!==void 0&&I.push(`Cyc ${m.battery_cycle_count}`),m.account_status&&I.push(`Akun ${m.account_status}`),m.catatan&&I.push(`Cat ${m.catatan}`),I.length)for(const J of q(`  ${I.join(" | ")}`,e))r.text(J+`
`)}r.text(k("-",e))}r.cmd(a.BOLD_ON).text(`PER METODE BAYAR
`).cmd(a.BOLD_OFF);for(const m of u)r.text(l(`${m.nama} (${m.count}x)`,s(m.total),e)+`
`),m.is_tunai&&Number(n.total_kembalian)>0&&(r.text(l("  Kembalian","-"+s(n.total_kembalian),e)+`
`),r.text(l("  Nett Tunai",s(m.total-n.total_kembalian),e)+`
`)),Number(m.biaya_tambahan)&&r.text(l("  Biaya",s(m.biaya_tambahan),e)+`
`);if(r.text(k("-",e)),r.cmd(a.BOLD_ON),r.text(l("VOID",`${U.jumlah||0} trx`,e)+`
`),r.cmd(a.BOLD_OFF),r.text(l("Nominal Void",s(U.nominal),e)+`
`),r.text(k("-",e)),r.cmd(a.BOLD_ON),r.text(l("RETUR",`${P.jumlah||0} trx`,e)+`
`),r.cmd(a.BOLD_OFF),r.text(l("Total Refund",s(P.total_refund),e)+`
`),Number(P.total_refund)){const m=P.sesi_ini||{},A=P.sesi_sebelumnya||{};r.text(l(`  Sesi Ini (${m.jumlah||0})`,s(m.nominal),e)+`
`),r.text(l(`  Sesi Sblm (${A.jumlah||0})`,s(A.nominal),e)+`
`)}r.text(k("-",e)),r.cmd(a.BOLD_ON).text(`KAS (Uang Fisik di Laci)
`).cmd(a.BOLD_OFF),r.text(l("Setor Awal",s(S.setor_awal),e)+`
`),r.text(l("Penjualan Tunai (net)","+"+s(S.penjualan_tunai),e)+`
`);const R=Number(S.kas_masuk||0),H=S.kas_masuk_detail||[];r.text(l(`Kas Masuk${H.length?` (${H.length}x)`:""}`,R?"+"+s(R):s(0),e)+`
`);for(const m of H)for(const A of tt(`  ${m.keterangan||"-"}`,"+"+s(m.nominal),e))r.text(A+`
`);const X=Number(S.kas_keluar||0),nt=S.kas_keluar_detail||[];r.text(l(`Kas Keluar${nt.length?` (${nt.length}x)`:""}`,X?"-"+s(X):s(0),e)+`
`);for(const m of nt)for(const A of tt(`  ${m.keterangan||"-"}`,"-"+s(m.nominal),e))r.text(A+`
`);const at=Number(S.refund_tunai||0);if(r.text(l("Refund Retur (Cash)",at?"-"+s(at):s(0),e)+`
`),r.text(k("-",e)),r.cmd(a.BOLD_ON),r.text(l("Saldo Kas",s(S.saldo),e)+`
`),r.cmd(a.BOLD_OFF),r.text(k("-",e)),_.ended_at){if(r.cmd(a.BOLD_ON).text(`REKONSILIASI KAS
`).cmd(a.BOLD_OFF),r.text(l("Saldo Sistem",s(_.saldo_system),e)+`
`),_.saldo_fisik!==null&&_.saldo_fisik!==void 0){r.text(l("Uang Fisik di Laci",s(_.saldo_fisik),e)+`
`);const m=Number(_.selisih||0),A=m===0?"Cocok":m>0?"Lebih":"Kurang",I=(m>0?"+":"")+s(m)+" ("+A+")";r.cmd(a.BOLD_ON),r.text(l("Selisih",I,e)+`
`),r.cmd(a.BOLD_OFF)}else r.text(l("Uang Fisik di Laci","Belum di-input",e)+`
`);_.closing_notes&&r.text("Catatan: "+_.closing_notes+`
`),r.text(k("-",e))}return r.cmd(a.BOLD_ON).text(`RINGKASAN
`).cmd(a.BOLD_OFF),r.text(l("Total Tunai",s(K.total_tunai),e)+`
`),r.text(l("Total Non-Tunai",s(K.total_non_tunai),e)+`
`),r.text(k("=",e)),y?(r.cmd(a.BOLD_ON),r.text(l("TOTAL SEMUA",s(K.total_semua),e)+`
`),r.cmd(a.BOLD_OFF)):(r.cmd(a.DOUBLE),r.text(l("TOTAL SEMUA",s(K.total_semua),e/2|0)+`
`),r.cmd(a.NORMAL).cmd(a.BOLD_OFF)),r.text(k("=",e)),z(r,p),r.toBase64()}function xt(n={}){const{charWidth:h=42}=n,e=new Y;return e.cmd(a.INIT_FEED).cmd(a.CENTER).cmd(a.DOUBLE),e.text(`TEST PRINT
`),e.cmd(a.NORMAL),e.text(`POSIP Thermal Print
`),e.text(k("=",h)),e.cmd(a.LEFT),e.text(`Printer is working correctly!
`),e.text(`Paper width: ${h} chars
`),e.text(k("-",h)),e.text(l("LEFT ALIGN","RIGHT ALIGN",h)+`
`),e.text(k("-",h)),e.cmd(a.CENTER).text(`END OF TEST
`),z(e,4),e.toBase64()}function pt(){const n=new Y;return n.cmd(a.INIT).cmd(a.DRAWER_2),n.toBase64()}return{buildReceipt:w,buildReturReceipt:M,buildCashReceipt:et,buildShiftReport:bt,buildTestPage:xt,drawerBytes:pt}}export{Vt as a,Ht as i,Gt as n,gt as r,qt as u};
