import{A as Q,K as H}from"./vue-core-Dr_IIeMI.js";import{u as bt}from"./useFormatters-BduZ5geO.js";import{u as xt}from"./useReceiptPdf-DtObSeKx.js";import{a as pt}from"./index-DiCCG0Fd.js";function kt(t=null,i={}){const o=(l,f)=>{const _=String(l??"").trim();return _!==""?_:f??""};return{name:o(t==null?void 0:t.store_name,i.name||"POSIP"),address:o(t==null?void 0:t.store_address,i.address||""),phone:o(t==null?void 0:t.store_phone,i.phone||""),email:o(t==null?void 0:t.store_email,i.email||""),npwp:o(t==null?void 0:t.store_npwp,i.npwp||""),receiptFooter:o(t==null?void 0:t.receipt_footer,i.receiptFooter||"")}}function Ct(t,i={}){return!t||typeof t!="object"?kt(null,i):{name:String(t.name||i.name||"POSIP").trim()||"POSIP",address:String(t.address??i.address??""),phone:String(t.phone??i.phone??""),email:String(t.email??i.email??""),npwp:String(t.npwp??i.npwp??""),receiptFooter:String(t.receipt_footer??t.receiptFooter??i.receiptFooter??"")}}const tt="posip-thermal-printer",st=new Set(["bluetooth","serial","usb"]);function yt(t){if(!t||typeof t!="object")return null;const i=t.kind;if(!st.has(i))return null;const o={kind:i},l=t.terminalUlid,f=t.label;return typeof l=="string"&&l.trim()&&(o.terminalUlid=l.trim()),typeof f=="string"&&f.trim()&&(o.label=f.trim()),o}function j(){try{const t=localStorage.getItem(tt);return t?yt(JSON.parse(t)):null}catch{return null}}function Ot(t){if(!(t!=null&&t.kind)||!st.has(t.kind))throw new Error("Invalid printer kind");const i={kind:t.kind};t.terminalUlid&&(i.terminalUlid=t.terminalUlid),t.label&&(i.label=t.label),localStorage.setItem(tt,JSON.stringify(i))}function Nt(){try{localStorage.removeItem(tt)}catch{}}function Mt(t){const i=j();return i?!t||!i.terminalUlid?!0:i.terminalUlid===t:!1}function gt(t){if(typeof t!="string"||!t.length)return new Uint8Array(0);const i=t.trim();if(!i.length)return new Uint8Array(0);const o=atob(i),l=new Uint8Array(o.length);for(let f=0;f<o.length;f++)l[f]=o.charCodeAt(f);return l}const Lt=[27,112,0,25,25],Bt=[29,86,1];let g=null;const Tt=[6384,65504,65280,"49535343-fe7d-4ae5-8fa9-9fafd205e455","e7810a71-73ae-499d-8c15-faa9aef0c3f2","6e400001-b5a3-f393-e0a9-e50e24dcca9e"];function et(t=typeof navigator<"u"?navigator:{}){return t}function Z(){return g}function nt(t){const i=et(t);return{bluetooth:!!i.bluetooth,serial:!!i.serial,usb:!!i.usb}}function ct(t){const i=nt(t);return i.bluetooth||i.serial||i.usb}function St(t,i){i==null||i(),g&&(g.disconnect().catch(()=>{}),g=null)}async function ut(t){const o=await(await t.gatt.connect()).getPrimaryServices();let l=null;for(const b of o){const O=await b.getCharacteristics();for(const c of O)if(c.properties.write||c.properties.writeWithoutResponse){l=c;break}if(l)break}if(!l)throw new Error("Karakteristik tulis printer Bluetooth tidak ditemukan.");const f=l.properties.writeWithoutResponse&&!l.properties.write,_=l;return{kind:"bluetooth",label:t.name||"Printer Bluetooth",async write(b){for(let c=0;c<b.length;c+=180){const I=b.slice(c,c+180);f&&_.writeValueWithoutResponse?await _.writeValueWithoutResponse(I):await _.writeValue(I),await new Promise(C=>setTimeout(C,18))}},async disconnect(){var b;try{(b=t.gatt)==null||b.disconnect()}catch{}}}}function lt(t){return{kind:"serial",label:"Printer USB (Serial)",async write(i){const o=t.writable.getWriter();try{await o.write(i)}finally{o.releaseLock()}},async disconnect(){try{await t.close()}catch{}}}}async function ft(t){await t.open(),t.configuration===null&&await t.selectConfiguration(1);let i=0,o=1;for(const l of t.configuration.interfaces)for(const f of l.alternates)if(f.interfaceClass===7||f.interfaceClass===255){i=l.interfaceNumber;const _=f.endpoints.find(b=>b.direction==="out");_&&(o=_.endpointNumber)}return await t.claimInterface(i),{kind:"usb",label:t.productName||"Printer USB",async write(l){for(let _=0;_<l.length;_+=4096)await t.transferOut(o,l.slice(_,_+4096))},async disconnect(){try{await t.close()}catch{}}}}async function Et(t){if(!t.bluetooth)throw new Error("Browser tidak mendukung Web Bluetooth (pakai Chrome/Edge).");const i=await t.bluetooth.requestDevice({acceptAllDevices:!0,optionalServices:Tt});return g=await ut(i),g}async function Ft(t){if(!t.serial)throw new Error("Browser tidak mendukung Web Serial (pakai Chrome/Edge desktop).");const i=await t.serial.requestPort();return await i.open({baudRate:9600}),g=lt(i),g}async function Dt(t){if(!t.usb)throw new Error("Browser tidak mendukung WebUSB.");const i=await t.usb.requestDevice({filters:[{classCode:7},{classCode:255}]});return g=await ft(i),g}async function Rt(t,i){const o=et(i);return t==="bluetooth"?Et(o):t==="serial"?Ft(o):Dt(o)}async function mt(t,i){var l,f,_;if(g)return g;const o=et(i);try{if(t==="serial"&&((l=o.serial)!=null&&l.getPorts)){const b=await o.serial.getPorts();if(b.length){try{await b[0].open({baudRate:9600})}catch{}return g=lt(b[0]),g}}if(t==="bluetooth"&&((f=o.bluetooth)!=null&&f.getDevices)){const b=await o.bluetooth.getDevices();if(b.length)return g=await ut(b[0]),g}if(t==="usb"&&((_=o.usb)!=null&&_.getDevices)){const b=await o.usb.getDevices();if(b.length)return g=await ft(b[0]),g}}catch{}return null}async function wt(t,i={}){const{writeFn:o,reconnectFn:l}=i;if(!t)return{ok:!1,error:"Data cetak kosong"};let f;try{f=gt(t)}catch{return{ok:!1,error:"Data base64 tidak valid"}}if(!f.length)return{ok:!1,error:"Payload ESC/POS kosong"};const _=j();let b=Z();if(b||(b=await(l||(()=>mt((_==null?void 0:_.kind)??null)))()),b)try{return await(o||(c=>b.write(c)))(f),{ok:!0}}catch(O){return{ok:!1,error:(O==null?void 0:O.message)||"Gagal mengirim ke printer"}}return _!=null&&_.kind?{ok:!1,needPicker:!0,error:"Printer perlu disambungkan ulang"}:{ok:!1,needPicker:!0,error:"Printer thermal belum dipasangkan"}}async function Pt(){return!!(Z()||j())}function At(){const t=Q(Z()),i=Q(null),o=H(()=>ct()),l=H(()=>nt()),f=H(()=>{var N,L;return((N=t.value)==null?void 0:N.label)||((L=j())==null?void 0:L.label)||null}),_=H(()=>!!t.value);function b(){t.value=Z()}async function O(N,{terminalUlid:L,label:B}={}){i.value=null;try{const D=await Rt(N);return Ot({kind:N,terminalUlid:L,label:B||D.label}),t.value=D,D}catch(D){throw i.value=(D==null?void 0:D.message)||"Gagal menghubungkan printer",D}}async function c(){i.value=null;const N=j(),L=await mt((N==null?void 0:N.kind)??null);return t.value=L,L}function I(){St(j(),Nt),t.value=null,i.value=null}async function C(N){const L=t.value||await c();if(!L)throw new Error("Printer belum dipasangkan");await L.write(N)}return{connection:t,lastError:i,supported:o,support:l,printerLabel:f,isConnected:_,pick:O,reconnect:c,forget:I,write:C,syncConnection:b}}function Wt(){const t=At(),i=Q(!1),o=Q(!1),l=Q(null),f=H(()=>ct()),_=H(()=>nt()),b=H(()=>{var B;return t.printerLabel.value||((B=j())==null?void 0:B.label)||null});async function O(){const B=await Pt();return i.value=B,B}function c(){var B;return!!(Z()||(B=j())!=null&&B.kind)}async function I(B,D){return t.pick(B,D)}async function C(){return t.reconnect()}function N(){t.forget()}async function L(B,D={}){o.value=!0,l.value=null;try{const M=await wt(B,{writeFn:X=>t.write(X),reconnectFn:()=>t.reconnect()});return M.ok||(l.value=M.error||"Cetak gagal"),{success:M.ok,needPicker:M.needPicker||!1,message:M.error}}finally{o.value=!1}}return{isAvailable:i,busy:o,error:l,supported:f,support:_,printerLabel:b,checkStatus:O,isReadyToThermal:c,pick:I,reconnect:C,forget:N,printRaw:L,transport:t}}function $t(t,i,o,l){const f=[];if(f.push(o("PENJUALAN",`${t.jumlah_transaksi||0} trx`,l)),f.push(o("Penjualan Kotor",i(t.penjualan_kotor),l)),Number(t.diskon_item)>0){f.push(o("Diskon Item","-"+i(t.diskon_item),l));for(let _=1;_<=5;_++){const b=Number(t[`diskon_line_${_}`]||0);if(b>0){const O=_===5?" (Manual)":"";f.push(o(`  Line ${_}${O}`,"-"+i(b),l))}}}else f.push(o("Diskon Item",i(0),l));return Number(t.diskon_nota)>0?(f.push(o("Diskon Nota","-"+i(t.diskon_nota),l)),Number(t.diskon_nota_l1)>0&&f.push(o("  Tipe Customer (L1)","-"+i(t.diskon_nota_l1),l)),Number(t.diskon_nota_l2)>0&&f.push(o("  Kategori Customer (L2)","-"+i(t.diskon_nota_l2),l)),Number(t.diskon_nota_l3)>0&&f.push(o("  Manual Kasir (L3)","-"+i(t.diskon_nota_l3),l))):f.push(o("Diskon Nota",i(0),l)),f.push(o("Penjualan Bersih",i(t.penjualan_bersih),l)),f.push(o("Biaya Kirim",i(t.biaya_kirim),l)),f.push(o("Biaya Lain",i(t.biaya_lain),l)),t.pajak_nama?f.push(o(`Pajak (${t.pajak_nama} ${t.pajak_persen}%)`,i(t.pajak_nominal),l)):f.push(o("Pajak",i(t.pajak_nominal),l)),f.push(o("Pembulatan",i(t.pembulatan),l)),f.push(o("OMZET",i(t.omzet),l)),f}function It(t=4,i=!1){const o=[];i&&o.push(...Lt);const l=Math.min(Math.max(t,0),10);return l>0&&o.push(27,100,l),o.push(...Bt),new Uint8Array(o)}const a={INIT:[27,64],INIT_FEED:[27,64,10],CENTER:[27,97,1],LEFT:[27,97,0],BOLD_ON:[27,69,1],BOLD_OFF:[27,69,0],DOUBLE:[27,33,48],NORMAL:[27,33,0],DRAWER_2:[27,112,0,25,25]};class Y{constructor(){this._parts=[]}cmd(i){return this._parts.push(new Uint8Array(i)),this}text(i){const o=new Uint8Array(i.length);for(let l=0;l<i.length;l++){const f=i.charCodeAt(l);o[l]=f<128?f:63}return this._parts.push(o),this}toBytes(){let i=0;for(const f of this._parts)i+=f.length;const o=new Uint8Array(i);let l=0;for(const f of this._parts)o.set(f,l),l+=f.length;return o}toBase64(){const i=this.toBytes();let o="";for(let l=0;l<i.length;l++)o+=String.fromCharCode(i[l]);return btoa(o)}}function p(t,i){return t.repeat(i)+`
`}function s(t,i,o){const l=i.length,f=o-l-1;return(t.length>f?t.slice(0,f):t+" ".repeat(Math.max(0,f-t.length)))+" "+i}function ot(t,i){if(t.length<=i)return[t];const o=(t.match(/^\s*/)||[""])[0],l=t.trim().split(/\s+/),f=[];let _=o;for(const b of l){const O=_.trim()===""?o+b:_+" "+b;O.length>i&&_.trim()!==""?(f.push(_),_=o+b):_=O}return _.trim()!==""&&f.push(_),f.length?f:[t]}function z(t,i,o=!1){t.cmd(Array.from(It(i,o)))}function Gt(){const{formatCurrency:t,formatNumber:i,formatQty:o,formatPercent:l,formatDateTime:f}=bt(),_=pt(),{buildReturPolicyText:b}=xt();function O(e){return e==null?"0":i(Math.abs(Number(e)))}function c(e){if(e==null)return"0";const x=Number(e),n=O(x);return x<0?`-${n}`:n}function I(e){const x=[];return e.kode_internal&&x.push(e.kode_internal),x.push(`SN ${e.serial_number||"-"}`),e.grade&&x.push(e.grade),e.battery_health!==null&&e.battery_health!==void 0&&e.battery_health!==""?x.push(`Bat ${e.battery_health}%${e.battery_condition?" "+e.battery_condition:""}`):e.battery_condition&&x.push(`Bat ${e.battery_condition}`),e.battery_cycle_count!==null&&e.battery_cycle_count!==void 0&&e.battery_cycle_count!==""&&x.push(`Cyc ${e.battery_cycle_count}`),e.account_status&&x.push(e.account_status),{main:x.join(" . "),catatan:e.catatan||""}}function C(e){const x=[];for(let n=1;n<=5;n++){const k=e[`diskon_${n}_tipe`],y=Number(e[`diskon_${n}_nilai`]||0);k==="none"||y===0||x.push(k==="percent"?l(y):t(y))}return x.join("+")}function N(e,x,n){if(!x||!n)return e;const k=x==="percent"?l(n):c(n);return`${e} (${k})`}function L(e,x,n,k=null){const y=k||_.store;if(e.cmd(a.CENTER),n?e.cmd(a.BOLD_ON).text((y.name||"POSIP")+`
`).cmd(a.BOLD_OFF):e.cmd(a.DOUBLE).text((y.name||"POSIP")+`
`).cmd(a.NORMAL),y.address)for(const w of String(y.address).split(/\r?\n/))w.trim()&&e.text(w+`
`);y.phone&&e.text("Telp: "+y.phone+`
`),y.email&&e.text("Email: "+y.email+`
`),y.npwp&&e.text("NPWP: "+y.npwp+`
`),e.text(p("=",x))}function B(e,x={}){var $,K,P,W,G,J;const{charWidth:n=42,feedLines:k=4,compact:y=!1,returPolicy:w=null,footer:r=null,openDrawer:d=!1,store:U=null}=x,u=new Y;u.cmd(a.INIT_FEED),L(u,n,y,U),u.cmd(a.LEFT),u.text(s("No",": "+(e.nomor_dokumen||"-"),n)+`
`),u.text(s("Tgl",": "+f(e.tanggal),n)+`
`),($=e.created_by)!=null&&$.name&&u.text(s("Kasir",": "+e.created_by.name,n)+`
`);const A=(K=e.customer)==null?void 0:K.nama;A&&A!=="Walk-in"&&u.text(s("Cust",": "+A,n)+`
`),u.text(p("-",n));for(const h of e.details||[]){u.text((((P=h.product)==null?void 0:P.nama_produk)||"")+`
`);const T=Number(h.qty||0)*Number(h.harga_satuan||0);if(u.text(s(`  ${o(h.qty)} ${h.unit||""} x ${c(h.harga_satuan)}`,c(T),n)+`
`),Number(h.diskon_total)>0){const S=C(h);u.text(s(`    ${S}`,"-"+c(h.diskon_total),n)+`
`)}if((W=h.serial_units)!=null&&W.length)for(const S of h.serial_units){const{main:q,catatan:v}=I(S);for(const V of ot("  "+q,n))u.text(V+`
`);if(v)for(const V of ot("    Cat: "+v,n))u.text(V+`
`)}}u.text(p("-",n)),u.text(s("Subtotal",c(e.subtotal),n)+`
`);for(let h=1;h<=3;h++){const T=Number(e[`diskon_nota_${h}_hasil`]||0);if(T>0){const S=e[`_disc_label_${h}`]||e[`diskon_nota_${h}_label`],q=h===3?"Disc Manual":`Disc ${h}`,v=S?N(S,e[`diskon_nota_${h}_tipe`],e[`diskon_nota_${h}_nilai`]):N(q,e[`diskon_nota_${h}_tipe`],e[`diskon_nota_${h}_nilai`]);u.text(s("  "+v,"-"+c(T),n)+`
`)}}if(Number(e.total_diskon)>0&&u.text(s("Total",c(e.total_setelah_diskon),n)+`
`),Number(e.biaya_kirim_hasil)>0){const h=N("Biaya Kirim",e.biaya_kirim_tipe,e.biaya_kirim_nilai);u.text(s(h,c(e.biaya_kirim_hasil),n)+`
`)}if(Number(e.biaya_lain_hasil)>0){const h=N("Biaya Lain",e.biaya_lain_tipe,e.biaya_lain_nilai);u.text(s(h,c(e.biaya_lain_hasil),n)+`
`)}Number(e.pajak_nominal)>0&&(u.text(s("DPP",c(e.dpp),n)+`
`),u.text(s(`${e.pajak_nama||"PPN"} ${e.pajak_persen}%`,c(e.pajak_nominal),n)+`
`)),Number(e.pembulatan)&&u.text(s("Pembulatan",c(e.pembulatan),n)+`
`),u.text(p("-",n)),u.cmd(a.BOLD_ON),u.text(s("GRAND TOTAL",c(e.grand_total),n)+`
`),u.cmd(a.BOLD_OFF),u.text(p("-",n));for(const h of e.payments||[])u.text(s(((G=h.metode_pembayaran)==null?void 0:G.nama_pembayaran)||"",c(h.nominal),n)+`
`),Number(h.biaya_tambahan)>0&&u.text(s("  Biaya",c(h.biaya_tambahan),n)+`
`);Number(e.total_bayar)&&(u.cmd(a.BOLD_ON),u.text(s("Total Bayar",c(e.total_bayar),n)+`
`),u.cmd(a.BOLD_OFF)),Number(e.kembalian)>0&&(u.cmd(a.BOLD_ON),u.text(s("Kembali",c(e.kembalian),n)+`
`),u.cmd(a.BOLD_OFF)),u.text(p("=",n));const R=e.returns||[];if(R.length>0){u.cmd(a.BOLD_ON).text(`RIWAYAT RETUR
`).cmd(a.BOLD_OFF);for(const T of R){u.text(s(T.nomor_dokumen||"","Tunai",n)+`
`),u.text("  "+f(T.tanggal)+`
`);for(const S of T.details||[])u.text(s(`  ${((J=S.product)==null?void 0:J.nama_produk)||""} x${o(S.qty)}`,`@ ${c(S.harga_satuan)}`,n)+`
`);Number(T.pembulatan)&&u.text(s("  Pembulatan",c(T.pembulatan),n)+`
`),u.cmd(a.BOLD_ON).text(s("  Total Retur",c(T.grand_total),n)+`
`).cmd(a.BOLD_OFF)}u.text(p("-",n)),u.cmd(a.BOLD_ON).text(`RINGKASAN
`).cmd(a.BOLD_OFF),u.text(s("Pembayaran Asli",c(e.grand_total),n)+`
`),(Number(e.biaya_kirim_hasil)>0||Number(e.biaya_lain_hasil)>0)&&(u.text(`Tidak Termasuk Retur:
`),Number(e.biaya_kirim_hasil)>0&&u.text(s("  Biaya Kirim",c(e.biaya_kirim_hasil),n)+`
`),Number(e.biaya_lain_hasil)>0&&u.text(s("  Biaya Lain",c(e.biaya_lain_hasil),n)+`
`));const h=R.reduce((T,S)=>T+Number(S.grand_total||0),0);u.text(s("Total Semua Retur",c(h),n)+`
`),u.cmd(a.BOLD_ON),u.text(s("NILAI BERSIH",c(Number(e.grand_total)-h),n)+`
`),u.cmd(a.BOLD_OFF),u.text(`(Pembayaran - Retur)
`),u.text(p("=",n))}if(e.status==="voided"?(u.cmd(a.CENTER).cmd(a.BOLD_ON),y||u.cmd(a.DOUBLE),u.text(`*** VOID ***
`),u.cmd(a.NORMAL).cmd(a.BOLD_OFF)):R.length>0&&(u.cmd(a.CENTER).cmd(a.BOLD_ON),u.text(`*** RETUR ***
`),u.cmd(a.BOLD_OFF)),w){const h=b(w,e.tanggal);h&&u.cmd(a.CENTER).text(h+`
`)}const E=r||"Terima Kasih!";u.cmd(a.CENTER);for(const h of E.split(`
`))u.text(h+`
`);return e.notes&&u.cmd(a.CENTER).text(e.notes+`
`),z(u,k,d),u.toBase64()}function D(e,x,n={}){var A,R;const{charWidth:k=42,feedLines:y=4,compact:w=!1,store:r=null}=n,d=new Y;d.cmd(a.INIT_FEED),L(d,k,w,r),d.cmd(a.CENTER).cmd(a.BOLD_ON).text(`STRUK RETUR
`).cmd(a.BOLD_OFF).cmd(a.LEFT),d.text(p("=",k)),d.text(s("No Retur",": "+(e.nomor_dokumen||"-"),k)+`
`),d.text(s("No Nota",": "+((x==null?void 0:x.nomor_dokumen)||"-"),k)+`
`),d.text(s("Tgl",": "+f(e.tanggal||new Date),k)+`
`),(A=e.created_by)!=null&&A.name&&d.text(s("Kasir",": "+e.created_by.name,k)+`
`),d.text(p("-",k));for(const E of e.details||[]){const $=((R=E.product)==null?void 0:R.nama_produk)||"",K=E.qty||0,P=E.harga_satuan||E.harga_per_base||0,W=Number(K)*Number(P);d.text($+`
`),d.text(s(`  ${o(K)} x ${c(P)}`,c(W),k)+`
`)}d.text(p("-",k));const U=Number(e.subtotal||0),u=Number(e.pembulatan||0);return U&&d.text(s("Subtotal",c(U),k)+`
`),u&&d.text(s("Pembulatan",c(u),k)+`
`),d.cmd(a.BOLD_ON),d.text(s("TOTAL RETUR",c(e.grand_total),k)+`
`),d.cmd(a.BOLD_OFF),d.text(p("-",k)),d.text(s("Metode Refund","Tunai",k)+`
`),d.text(p("=",k)),d.cmd(a.CENTER).text(`Terima Kasih
`),z(d,y),d.toBase64()}function M(e,x={}){const{charWidth:n=42,feedLines:k=4,compact:y=!1,store:w=null}=x,r=new Y;r.cmd(a.INIT_FEED),L(r,n,y,w);const U={kas_masuk:"KAS MASUK",kas_keluar:"KAS KELUAR",setor_awal:"SETOR AWAL"}[e.tipe]||"TRANSAKSI KAS";return r.cmd(a.CENTER).cmd(a.BOLD_ON).text(U+`
`).cmd(a.BOLD_OFF).cmd(a.LEFT),r.text(p("=",n)),r.text(s("Terminal",": "+(e.terminal||"-"),n)+`
`),r.text(s("Kasir",": "+(e.kasir||"-"),n)+`
`),r.text(s("Tanggal",": "+(e.date||"-"),n)+`
`),r.text(p("-",n)),r.cmd(a.BOLD_ON),r.text(s("Nominal",c(e.nominal),n)+`
`),r.cmd(a.BOLD_OFF),e.keterangan&&r.text("Ket: "+e.keterangan+`
`),r.text(p("=",n)),z(r,k),r.toBase64()}function X(e,x={}){var it,rt,at;const{charWidth:n=42,feedLines:k=4,compact:y=!1,store:w=null}=x,r=new Y,d=e.shift||{},U=e.penjualan||{},u=e.payment_breakdown||[],A=e.void||{},R=e.retur||{},E=e.kas||{},$=e.ringkasan||{};r.cmd(a.INIT_FEED),L(r,n,y,w),r.cmd(a.CENTER).cmd(a.BOLD_ON).text(`LAPORAN SHIFT
`).cmd(a.BOLD_OFF),d.ulid&&r.text(d.ulid+`
`),r.cmd(a.LEFT),r.text(p("=",n)),r.text(s("Terminal",": "+(((it=d.terminal)==null?void 0:it.kode_terminal)||"-"),n)+`
`),r.text(s("Kasir",": "+(((rt=d.user)==null?void 0:rt.name)||"-"),n)+`
`),r.text(s("Mulai",": "+(d.started_at?f(d.started_at):"-"),n)+`
`),r.text(s("Selesai",": "+(d.ended_at?f(d.ended_at):"-"),n)+`
`);let K="Masih Aktif";d.ended_at&&(K=d.ended_by_force?`Ditutup Paksa oleh ${((at=d.forced_by_user)==null?void 0:at.name)||"Admin"}`:"Ditutup Normal"),r.text(s("Status",": "+K,n)+`
`),r.text(p("-",n));const P=$t(U,c,s,n),W=P.length?P[P.length-1]:null,G=P.slice(0,-1);if(G.length){r.cmd(a.BOLD_ON).text(G[0]+`
`).cmd(a.BOLD_OFF);for(let m=1;m<G.length;m++)r.text(G[m]+`
`)}const J=u.reduce((m,F)=>m+Number(F.biaya_tambahan||0),0);J>0&&r.text(s("Biaya Pembayaran",c(J),n)+`
`),W&&r.cmd(a.BOLD_ON).text(W+`
`).cmd(a.BOLD_OFF),r.text(p("-",n));const h=e.serial_units_sold||[];if(h.length){r.cmd(a.BOLD_ON),r.text(s("UNIT SERIAL TERJUAL",`${h.length} unit`,n)+`
`),r.cmd(a.BOLD_OFF);for(const m of h){r.text(s(m.product||"-",c(m.harga),n)+`
`),r.text(`  ${m.kode_internal||"SN "+(m.serial_number||"-")} | ${m.nomor_dokumen||"-"}
`);const F=[];m.kode_internal&&m.serial_number&&F.push(`SN ${m.serial_number}`),m.grade&&F.push(`Grade ${m.grade}`),m.battery_health!==null&&m.battery_health!==void 0&&F.push(`Bat ${m.battery_health}%`),m.battery_cycle_count!==null&&m.battery_cycle_count!==void 0&&F.push(`Cyc ${m.battery_cycle_count}`),m.account_status&&F.push(`Akun ${m.account_status}`),m.catatan&&F.push(`Cat ${m.catatan}`),F.length&&r.text(`  ${F.join(" | ")}
`)}r.text(p("-",n))}r.cmd(a.BOLD_ON).text(`PER METODE BAYAR
`).cmd(a.BOLD_OFF);for(const m of u)r.text(s(`${m.nama} (${m.count}x)`,c(m.total),n)+`
`),m.is_tunai&&Number(e.total_kembalian)>0&&(r.text(s("  Kembalian","-"+c(e.total_kembalian),n)+`
`),r.text(s("  Nett Tunai",c(m.total-e.total_kembalian),n)+`
`)),Number(m.biaya_tambahan)&&r.text(s("  Biaya",c(m.biaya_tambahan),n)+`
`);if(r.text(p("-",n)),r.cmd(a.BOLD_ON),r.text(s("VOID",`${A.jumlah||0} trx`,n)+`
`),r.cmd(a.BOLD_OFF),r.text(s("Nominal Void",c(A.nominal),n)+`
`),r.text(p("-",n)),r.cmd(a.BOLD_ON),r.text(s("RETUR",`${R.jumlah||0} trx`,n)+`
`),r.cmd(a.BOLD_OFF),r.text(s("Total Refund",c(R.total_refund),n)+`
`),Number(R.total_refund)){const m=R.sesi_ini||{},F=R.sesi_sebelumnya||{};r.text(s(`  Sesi Ini (${m.jumlah||0})`,c(m.nominal),n)+`
`),r.text(s(`  Sesi Sblm (${F.jumlah||0})`,c(F.nominal),n)+`
`)}r.text(p("-",n)),r.cmd(a.BOLD_ON).text(`KAS (Uang Fisik di Laci)
`).cmd(a.BOLD_OFF),r.text(s("Setor Awal",c(E.setor_awal),n)+`
`),r.text(s("Penjualan Tunai (net)","+"+c(E.penjualan_tunai),n)+`
`);const T=Number(E.kas_masuk||0),S=E.kas_masuk_detail||[];r.text(s(`Kas Masuk${S.length?` (${S.length}x)`:""}`,T?"+"+c(T):c(0),n)+`
`);for(const m of S)r.text(s(`  ${m.keterangan||"-"}`,"+"+c(m.nominal),n)+`
`);const q=Number(E.kas_keluar||0),v=E.kas_keluar_detail||[];r.text(s(`Kas Keluar${v.length?` (${v.length}x)`:""}`,q?"-"+c(q):c(0),n)+`
`);for(const m of v)r.text(s(`  ${m.keterangan||"-"}`,"-"+c(m.nominal),n)+`
`);const V=Number(E.refund_tunai||0);if(r.text(s("Refund Retur (Cash)",V?"-"+c(V):c(0),n)+`
`),r.text(p("-",n)),r.cmd(a.BOLD_ON),r.text(s("Saldo Kas",c(E.saldo),n)+`
`),r.cmd(a.BOLD_OFF),r.text(p("-",n)),d.ended_at){if(r.cmd(a.BOLD_ON).text(`REKONSILIASI KAS
`).cmd(a.BOLD_OFF),r.text(s("Saldo Sistem",c(d.saldo_system),n)+`
`),d.saldo_fisik!==null&&d.saldo_fisik!==void 0){r.text(s("Uang Fisik di Laci",c(d.saldo_fisik),n)+`
`);const m=Number(d.selisih||0),F=m===0?"Cocok":m>0?"Lebih":"Kurang",ht=(m>0?"+":"")+c(m)+" ("+F+")";r.cmd(a.BOLD_ON),r.text(s("Selisih",ht,n)+`
`),r.cmd(a.BOLD_OFF)}else r.text(s("Uang Fisik di Laci","Belum di-input",n)+`
`);d.closing_notes&&r.text("Catatan: "+d.closing_notes+`
`),r.text(p("-",n))}return r.cmd(a.BOLD_ON).text(`RINGKASAN
`).cmd(a.BOLD_OFF),r.text(s("Total Tunai",c($.total_tunai),n)+`
`),r.text(s("Total Non-Tunai",c($.total_non_tunai),n)+`
`),r.text(p("=",n)),y?(r.cmd(a.BOLD_ON),r.text(s("TOTAL SEMUA",c($.total_semua),n)+`
`),r.cmd(a.BOLD_OFF)):(r.cmd(a.DOUBLE),r.text(s("TOTAL SEMUA",c($.total_semua),n/2|0)+`
`),r.cmd(a.NORMAL).cmd(a.BOLD_OFF)),r.text(p("=",n)),z(r,k),r.toBase64()}function _t(e={}){const{charWidth:x=42}=e,n=new Y;return n.cmd(a.INIT_FEED).cmd(a.CENTER).cmd(a.DOUBLE),n.text(`TEST PRINT
`),n.cmd(a.NORMAL),n.text(`POSIP Thermal Print
`),n.text(p("=",x)),n.cmd(a.LEFT),n.text(`Printer is working correctly!
`),n.text(`Paper width: ${x} chars
`),n.text(p("-",x)),n.text(s("LEFT ALIGN","RIGHT ALIGN",x)+`
`),n.text(p("-",x)),n.cmd(a.CENTER).text(`END OF TEST
`),z(n,4),n.toBase64()}function dt(){const e=new Y;return e.cmd(a.INIT).cmd(a.DRAWER_2),e.toBase64()}return{buildReceipt:B,buildReturReceipt:D,buildCashReceipt:M,buildShiftReport:X,buildTestPage:_t,drawerBytes:dt}}export{Gt as a,Mt as i,Ct as n,kt as r,Wt as u};
