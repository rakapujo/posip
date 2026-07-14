import{A as W,K as M}from"./vue-core-DWH-uRDb.js";import{_ as Ot}from"./pdf-CwOZfpRE.js";import{u as Nt}from"./useFormatters-DxLpxg3Y.js";import{u as Lt}from"./useReceiptPdf-Ci0M_W5d.js";import{a as Tt}from"./index-DO5hXg_b.js";const nt="http://localhost:5123",st=3e3,Bt=15e3;function wt(){const t=W(!1),a=W([]);async function l(i,L){const N=new AbortController,k=setTimeout(()=>N.abort(),Bt);try{const y=await fetch(`${nt}${i}`,{method:"POST",signal:N.signal,headers:{"Content-Type":"application/json"},body:JSON.stringify(L)});return clearTimeout(k),y.ok?await y.json():{success:!1,message:(await y.json().catch(()=>({}))).message||"Print service error"}}catch(y){return clearTimeout(k),{success:!1,message:y.name==="AbortError"?"Print timeout":y.message||"Print service unavailable"}}}async function s(){try{const i=new AbortController,L=setTimeout(()=>i.abort(),st),N=await fetch(`${nt}/status`,{signal:i.signal});clearTimeout(L);const k=await N.json();return t.value=k.status==="ok",t.value}catch{return t.value=!1,!1}}async function f(){try{const i=new AbortController,L=setTimeout(()=>i.abort(),st),N=await fetch(`${nt}/printers`,{signal:i.signal});clearTimeout(L);const k=await N.json();return a.value=k.all||[],a.value}catch{return a.value=[],[]}}async function m(i,L,N=!1){return l("/print/raw",{printer:i,data:L,open_drawer:N})}async function g(i){return l("/drawer/open",{printer:i})}async function T(i,L){return l("/print/raw",{printer:i,data:L})}return{isAvailable:t,printers:a,checkStatus:s,getPrinters:f,printRaw:m,openDrawer:g,testPrint:T}}const at="posip-thermal-printer",ft=new Set(["bluetooth","serial","usb"]);function Et(t){if(!t||typeof t!="object")return null;const a=t.kind;if(!ft.has(a))return null;const l={kind:a},s=t.terminalUlid,f=t.label;return typeof s=="string"&&s.trim()&&(l.terminalUlid=s.trim()),typeof f=="string"&&f.trim()&&(l.label=f.trim()),l}function j(){try{const t=localStorage.getItem(at);return t?Et(JSON.parse(t)):null}catch{return null}}function St(t){if(!(t!=null&&t.kind)||!ft.has(t.kind))throw new Error("Invalid printer kind");const a={kind:t.kind};t.terminalUlid&&(a.terminalUlid=t.terminalUlid),t.label&&(a.label=t.label),localStorage.setItem(at,JSON.stringify(a))}function Rt(){try{localStorage.removeItem(at)}catch{}}function Vt(t){const a=j();return a?!t||!a.terminalUlid?!0:a.terminalUlid===t:!1}function Pt(t){if(typeof t!="string"||!t.length)return new Uint8Array(0);const a=atob(t),l=new Uint8Array(a.length);for(let s=0;s<a.length;s++)l[s]=a.charCodeAt(s);return l}const Dt=[27,112,0,25,25],Ft=[29,86,1];let B=null;const mt=[6384,65504,65280,"49535343-fe7d-4ae5-8fa9-9fafd205e455","e7810a71-73ae-499d-8c15-faa9aef0c3f2","6e400001-b5a3-f393-e0a9-e50e24dcca9e"];function At(t=typeof navigator<"u"?navigator:{}){return t}function Z(){return B}function X(t){const a=At(t);return{bluetooth:!!a.bluetooth,serial:!!a.serial,usb:!!a.usb}}function rt(t){const a=X(t);return a.bluetooth||a.serial||a.usb}function dt(t,a){a==null||a(),B&&(B.disconnect().catch(()=>{}),B=null)}async function _t(t){const l=await(await t.gatt.connect()).getPrimaryServices();let s=null;for(const g of l){const T=await g.getCharacteristics();for(const i of T)if(i.properties.write||i.properties.writeWithoutResponse){s=i;break}if(s)break}if(!s)throw new Error("Karakteristik tulis printer Bluetooth tidak ditemukan.");const f=s.properties.writeWithoutResponse&&!s.properties.write,m=s;return{kind:"bluetooth",label:t.name||"Printer Bluetooth",async write(g){for(let i=0;i<g.length;i+=180){const L=g.slice(i,i+180);f&&m.writeValueWithoutResponse?await m.writeValueWithoutResponse(L):await m.writeValue(L),await new Promise(N=>setTimeout(N,18))}},async disconnect(){var g;try{(g=t.gatt)==null||g.disconnect()}catch{}}}}function bt(t){return{kind:"serial",label:"Printer USB (Serial)",async write(a){const l=t.writable.getWriter();try{await l.write(a)}finally{l.releaseLock()}},async disconnect(){try{await t.close()}catch{}}}}async function ht(t){await t.open(),t.configuration===null&&await t.selectConfiguration(1);let a=0,l=1;for(const s of t.configuration.interfaces)for(const f of s.alternates)if(f.interfaceClass===7||f.interfaceClass===255){a=s.interfaceNumber;const m=f.endpoints.find(g=>g.direction==="out");m&&(l=m.endpointNumber)}return await t.claimInterface(a),{kind:"usb",label:t.productName||"Printer USB",async write(s){for(let m=0;m<s.length;m+=4096)await t.transferOut(l,s.slice(m,m+4096))},async disconnect(){try{await t.close()}catch{}}}}async function xt(t){if(!t.bluetooth)throw new Error("Browser tidak mendukung Web Bluetooth (pakai Chrome/Edge).");const a=await t.bluetooth.requestDevice({acceptAllDevices:!0,optionalServices:mt});return B=await _t(a),B}async function pt(t){if(!t.serial)throw new Error("Browser tidak mendukung Web Serial (pakai Chrome/Edge desktop).");const a=await t.serial.requestPort();return await a.open({baudRate:9600}),B=bt(a),B}async function kt(t){if(!t.usb)throw new Error("Browser tidak mendukung WebUSB.");const a=await t.usb.requestDevice({filters:[{classCode:7},{classCode:255}]});return B=await ht(a),B}async function yt(t,a){return t==="bluetooth"?xt(a):t==="serial"?pt(a):kt(a)}async function it(t,a){var l,s,f;if(B)return B;try{if(t==="serial"&&((l=a.serial)!=null&&l.getPorts)){const m=await a.serial.getPorts();if(m.length){try{await m[0].open({baudRate:9600})}catch{}return B=bt(m[0]),B}}if(t==="bluetooth"&&((s=a.bluetooth)!=null&&s.getDevices)){const m=await a.bluetooth.getDevices();if(m.length)return B=await _t(m[0]),B}if(t==="usb"&&((f=a.usb)!=null&&f.getDevices)){const m=await a.usb.getDevices();if(m.length)return B=await ht(m[0]),B}}catch{}return null}const It=Object.freeze(Object.defineProperty({__proto__:null,BT_SERVICES:mt,connectBluetooth:xt,connectByKind:yt,connectSerial:pt,connectUsb:kt,forgetPrinter:dt,getActiveConnection:Z,isThermalSupported:rt,supportMatrix:X,trySilentReconnect:it},Symbol.toStringTag,{value:"Module"}));let ct=!1;async function $t(t,a={}){const{openDrawer:l=!1,legacyPrinterId:s,legacy:f,writeFn:m,reconnectFn:g}=a;if(!t)return{ok:!1,error:"Data cetak kosong"};let T;try{T=Pt(t)}catch{return{ok:!1,error:"Data base64 tidak valid"}}if(!T.length)return{ok:!1,error:"Payload ESC/POS kosong"};const i=j();let L=Z();if(L||(L=await(g||(()=>it((i==null?void 0:i.kind)??null)))()),L)try{return await(m||(k=>L.write(k)))(T),{ok:!0}}catch(N){const k=(N==null?void 0:N.message)||"Gagal mengirim ke printer";if(s&&f){const y=await lt(f,s,t,l);if(y.ok)return y}return{ok:!1,error:k}}if(s&&f){const N=await lt(f,s,t,l);if(N.ok)return N}return i!=null&&i.kind?{ok:!1,needPicker:!0,error:"Printer perlu disambungkan ulang"}:s?{ok:!1,needPicker:!0,error:"Pasangkan printer di pengaturan terminal atau jalankan Print Service legacy"}:{ok:!1,needPicker:!0,error:"Printer thermal belum dipasangkan"}}async function lt(t,a,l,s){ct||(console.warn("[POSIP] Legacy Print Service (:5123) is deprecated. Pair printer via browser transport."),ct=!0);try{if(!await t.checkStatus())return{ok:!1,error:"Print service legacy tidak tersedia"};const m=await t.printRaw(a,l,s);return m.success?{ok:!0,legacyUsed:!0}:{ok:!1,error:m.message||"Legacy print gagal"}}catch(f){return{ok:!1,error:(f==null?void 0:f.message)||"Legacy print gagal"}}}async function vt(t,a){const{isThermalSupported:l}=await Ot(async()=>{const{isThermalSupported:s}=await Promise.resolve().then(()=>It);return{isThermalSupported:s}},void 0);if(l(t))return!0;if(a)try{return await a.checkStatus()}catch{return!1}return!1}function Ut(){const t=W(Z()),a=W(null),l=M(()=>rt()),s=M(()=>X()),f=M(()=>{var k,y;return((k=t.value)==null?void 0:k.label)||((y=j())==null?void 0:y.label)||null}),m=M(()=>!!t.value);function g(){t.value=Z()}async function T(k,{terminalUlid:y,label:G}={}){a.value=null;try{const O=await yt(k);return St({kind:k,terminalUlid:y,label:G||O.label}),t.value=O,O}catch(O){throw a.value=(O==null?void 0:O.message)||"Gagal menghubungkan printer",O}}async function i(){a.value=null;const k=j(),y=await it((k==null?void 0:k.kind)??null);return t.value=y,y}function L(){dt(j(),Rt),t.value=null,a.value=null}async function N(k){const y=t.value||await i();if(!y)throw new Error("Printer belum dipasangkan");await y.write(k)}return{connection:t,lastError:a,supported:l,support:s,printerLabel:f,isConnected:m,pick:T,reconnect:i,forget:L,write:N,syncConnection:g}}function qt(){const t=wt(),a=Ut(),l=W(!1),s=W(!1),f=W(null),m=M(()=>rt()),g=M(()=>X()),T=M(()=>{var O;return a.printerLabel.value||((O=j())==null?void 0:O.label)||null});async function i(){const O=await vt(typeof navigator<"u"?navigator:void 0,t);return l.value=O,O}async function L(O,$){return a.pick(O,$)}async function N(){return a.reconnect()}function k(){a.forget()}async function y(O,$={}){const{openDrawer:tt=!1,legacyPrinterId:et}=$;s.value=!0,f.value=null;try{const v=await $t(O,{openDrawer:tt,legacyPrinterId:et,legacy:t,writeFn:n=>a.write(n),reconnectFn:()=>a.reconnect()});return v.ok||(f.value=v.error||"Cetak gagal"),{success:v.ok,needPicker:v.needPicker||!1,message:v.error,legacyUsed:v.legacyUsed||!1}}finally{s.value=!1}}async function G(){var $;const O=j();return O!=null&&O.label?[{id:O.kind,name:O.label}]:a.printerLabel.value?[{id:(($=j())==null?void 0:$.kind)||"browser",name:a.printerLabel.value}]:[]}return{isAvailable:l,busy:s,error:f,supported:m,support:g,printerLabel:T,checkStatus:i,pick:L,reconnect:N,forget:k,printRaw:y,getPrinters:G,transport:a}}function Kt(t,a,l,s){const f=[];if(f.push(l("PENJUALAN",`${t.jumlah_transaksi||0} trx`,s)),f.push(l("Penjualan Kotor",a(t.penjualan_kotor),s)),Number(t.diskon_item)>0){f.push(l("Diskon Item","-"+a(t.diskon_item),s));for(let m=1;m<=5;m++){const g=Number(t[`diskon_line_${m}`]||0);if(g>0){const T=m===5?" (Manual)":"";f.push(l(`  Line ${m}${T}`,"-"+a(g),s))}}}else f.push(l("Diskon Item",a(0),s));return Number(t.diskon_nota)>0?(f.push(l("Diskon Nota","-"+a(t.diskon_nota),s)),Number(t.diskon_nota_l1)>0&&f.push(l("  Tipe Customer (L1)","-"+a(t.diskon_nota_l1),s)),Number(t.diskon_nota_l2)>0&&f.push(l("  Kategori Customer (L2)","-"+a(t.diskon_nota_l2),s)),Number(t.diskon_nota_l3)>0&&f.push(l("  Manual Kasir (L3)","-"+a(t.diskon_nota_l3),s))):f.push(l("Diskon Nota",a(0),s)),f.push(l("Penjualan Bersih",a(t.penjualan_bersih),s)),f.push(l("Biaya Kirim",a(t.biaya_kirim),s)),f.push(l("Biaya Lain",a(t.biaya_lain),s)),t.pajak_nama?f.push(l(`Pajak (${t.pajak_nama} ${t.pajak_persen}%)`,a(t.pajak_nominal),s)):f.push(l("Pajak",a(t.pajak_nominal),s)),f.push(l("Pembulatan",a(t.pembulatan),s)),f.push(l("OMZET",a(t.omzet),s)),f}function jt(t=4,a=!1){const l=[];a&&l.push(...Dt);const s=Math.min(Math.max(t,0),10);return s>0&&l.push(27,100,s),l.push(...Ft),new Uint8Array(l)}const o={INIT:[27,64],INIT_FEED:[27,64,10],CENTER:[27,97,1],LEFT:[27,97,0],BOLD_ON:[27,69,1],BOLD_OFF:[27,69,0],DOUBLE:[27,33,48],NORMAL:[27,33,0],DRAWER_2:[27,112,0,25,25]};class J{constructor(){this._parts=[]}cmd(a){return this._parts.push(new Uint8Array(a)),this}text(a){const l=new Uint8Array(a.length);for(let s=0;s<a.length;s++){const f=a.charCodeAt(s);l[s]=f<128?f:63}return this._parts.push(l),this}toBytes(){let a=0;for(const f of this._parts)a+=f.length;const l=new Uint8Array(a);let s=0;for(const f of this._parts)l.set(f,s),s+=f.length;return l}toBase64(){const a=this.toBytes();let l="";for(let s=0;s<a.length;s++)l+=String.fromCharCode(a[s]);return btoa(l)}}function p(t,a){return t.repeat(a)+`
`}function c(t,a,l){const s=a.length,f=l-s-1;return(t.length>f?t.slice(0,f):t+" ".repeat(Math.max(0,f-t.length)))+" "+a}function ut(t,a){if(t.length<=a)return[t];const l=(t.match(/^\s*/)||[""])[0],s=t.trim().split(/\s+/),f=[];let m=l;for(const g of s){const T=m.trim()===""?l+g:m+" "+g;T.length>a&&m.trim()!==""?(f.push(m),m=l+g):m=T}return m.trim()!==""&&f.push(m),f.length?f:[t]}function Q(t,a,l=!1){t.cmd(Array.from(jt(a,l)))}function Jt(){const{formatCurrency:t,formatNumber:a,formatQty:l,formatPercent:s,formatDateTime:f}=Nt(),m=Tt(),{buildReturPolicyText:g}=Lt();function T(n){return n==null?"0":a(Math.abs(Number(n)))}function i(n){if(n==null)return"0";const x=Number(n),e=T(x);return x<0?`-${e}`:e}function L(n){const x=[];return n.kode_internal&&x.push(n.kode_internal),x.push(`SN ${n.serial_number||"-"}`),n.grade&&x.push(n.grade),n.battery_health!==null&&n.battery_health!==void 0&&n.battery_health!==""?x.push(`Bat ${n.battery_health}%${n.battery_condition?" "+n.battery_condition:""}`):n.battery_condition&&x.push(`Bat ${n.battery_condition}`),n.account_status&&x.push(n.account_status),{main:x.join(" . "),catatan:n.catatan||""}}function N(n){const x=[];for(let e=1;e<=5;e++){const h=n[`diskon_${e}_tipe`],w=Number(n[`diskon_${e}_nilai`]||0);h==="none"||w===0||x.push(h==="percent"?s(w):t(w))}return x.join("+")}function k(n,x,e){if(!x||!e)return n;const h=x==="percent"?s(e):i(e);return`${n} (${h})`}function y(n,x,e){const h=m.store;if(n.cmd(o.CENTER),e?n.cmd(o.BOLD_ON).text((h.name||"POSIP")+`
`).cmd(o.BOLD_OFF):n.cmd(o.DOUBLE).text((h.name||"POSIP")+`
`).cmd(o.NORMAL),h.address)for(const w of String(h.address).split(/\r?\n/))w.trim()&&n.text(w+`
`);h.phone&&n.text("Telp: "+h.phone+`
`),h.email&&n.text("Email: "+h.email+`
`),h.npwp&&n.text("NPWP: "+h.npwp+`
`),n.text(p("=",x))}function G(n,x={}){var I,K,D,C,H,Y;const{charWidth:e=42,feedLines:h=4,compact:w=!1,returPolicy:r=null,footer:d=null,openDrawer:U=!1}=x,u=new J;u.cmd(o.INIT_FEED),y(u,e,w),u.cmd(o.LEFT),u.text(c("No",": "+(n.nomor_dokumen||"-"),e)+`
`),u.text(c("Tgl",": "+f(n.tanggal),e)+`
`),(I=n.created_by)!=null&&I.name&&u.text(c("Kasir",": "+n.created_by.name,e)+`
`);const A=(K=n.customer)==null?void 0:K.nama;A&&A!=="Walk-in"&&u.text(c("Cust",": "+A,e)+`
`),u.text(p("-",e));for(const _ of n.details||[]){u.text((((D=_.product)==null?void 0:D.nama_produk)||"")+`
`);const E=Number(_.qty||0)*Number(_.harga_satuan||0);if(u.text(c(`  ${l(_.qty)} ${_.unit||""} x ${i(_.harga_satuan)}`,i(E),e)+`
`),Number(_.diskon_total)>0){const S=N(_);u.text(c(`    ${S}`,"-"+i(_.diskon_total),e)+`
`)}if((C=_.serial_units)!=null&&C.length)for(const S of _.serial_units){const{main:V,catatan:z}=L(S);for(const q of ut("  "+V,e))u.text(q+`
`);if(z)for(const q of ut("    Cat: "+z,e))u.text(q+`
`)}}u.text(p("-",e)),u.text(c("Subtotal",i(n.subtotal),e)+`
`);for(let _=1;_<=3;_++){const E=Number(n[`diskon_nota_${_}_hasil`]||0);if(E>0){const S=n[`_disc_label_${_}`]||n[`diskon_nota_${_}_label`],V=S?k(S,n[`diskon_nota_${_}_tipe`],n[`diskon_nota_${_}_nilai`]):k(`Disc ${_}`,n[`diskon_nota_${_}_tipe`],n[`diskon_nota_${_}_nilai`]);u.text(c("  "+V,"-"+i(E),e)+`
`)}}if(Number(n.total_diskon)>0&&u.text(c("Total",i(n.total_setelah_diskon),e)+`
`),Number(n.biaya_kirim_hasil)>0){const _=k("Biaya Kirim",n.biaya_kirim_tipe,n.biaya_kirim_nilai);u.text(c(_,i(n.biaya_kirim_hasil),e)+`
`)}if(Number(n.biaya_lain_hasil)>0){const _=k("Biaya Lain",n.biaya_lain_tipe,n.biaya_lain_nilai);u.text(c(_,i(n.biaya_lain_hasil),e)+`
`)}Number(n.pajak_nominal)>0&&(u.text(c("DPP",i(n.dpp),e)+`
`),u.text(c(`${n.pajak_nama||"PPN"} ${n.pajak_persen}%`,i(n.pajak_nominal),e)+`
`)),Number(n.pembulatan)&&u.text(c("Pembulatan",i(n.pembulatan),e)+`
`),u.text(p("-",e)),u.cmd(o.BOLD_ON),u.text(c("GRAND TOTAL",i(n.grand_total),e)+`
`),u.cmd(o.BOLD_OFF),u.text(p("-",e));for(const _ of n.payments||[])u.text(c(((H=_.metode_pembayaran)==null?void 0:H.nama_pembayaran)||"",i(_.nominal),e)+`
`),Number(_.biaya_tambahan)>0&&u.text(c("  Biaya",i(_.biaya_tambahan),e)+`
`);Number(n.total_bayar)&&(u.cmd(o.BOLD_ON),u.text(c("Total Bayar",i(n.total_bayar),e)+`
`),u.cmd(o.BOLD_OFF)),Number(n.kembalian)>0&&(u.cmd(o.BOLD_ON),u.text(c("Kembali",i(n.kembalian),e)+`
`),u.cmd(o.BOLD_OFF)),u.text(p("=",e));const P=n.returns||[];if(P.length>0){u.cmd(o.BOLD_ON).text(`RIWAYAT RETUR
`).cmd(o.BOLD_OFF);for(const E of P){u.text(c(E.nomor_dokumen||"","Tunai",e)+`
`),u.text("  "+f(E.tanggal)+`
`);for(const S of E.details||[])u.text(c(`  ${((Y=S.product)==null?void 0:Y.nama_produk)||""} x${l(S.qty)}`,`@ ${i(S.harga_satuan)}`,e)+`
`);Number(E.pembulatan)&&u.text(c("  Pembulatan",i(E.pembulatan),e)+`
`),u.cmd(o.BOLD_ON).text(c("  Total Retur",i(E.grand_total),e)+`
`).cmd(o.BOLD_OFF)}u.text(p("-",e)),u.cmd(o.BOLD_ON).text(`RINGKASAN
`).cmd(o.BOLD_OFF),u.text(c("Pembayaran Asli",i(n.grand_total),e)+`
`),(Number(n.biaya_kirim_hasil)>0||Number(n.biaya_lain_hasil)>0)&&(u.text(`Tidak Termasuk Retur:
`),Number(n.biaya_kirim_hasil)>0&&u.text(c("  Biaya Kirim",i(n.biaya_kirim_hasil),e)+`
`),Number(n.biaya_lain_hasil)>0&&u.text(c("  Biaya Lain",i(n.biaya_lain_hasil),e)+`
`));const _=P.reduce((E,S)=>E+Number(S.grand_total||0),0);u.text(c("Total Semua Retur",i(_),e)+`
`),u.text(c("  Refund Tunai",i(_),e)+`
`),u.cmd(o.BOLD_ON),u.text(c("NILAI BERSIH",i(Number(n.grand_total)-_),e)+`
`),u.cmd(o.BOLD_OFF),u.text(`(Pembayaran - Retur)
`),u.text(p("=",e))}if(n.status==="voided"?(u.cmd(o.CENTER).cmd(o.BOLD_ON),w||u.cmd(o.DOUBLE),u.text(`*** VOID ***
`),u.cmd(o.NORMAL).cmd(o.BOLD_OFF)):P.length>0&&(u.cmd(o.CENTER).cmd(o.BOLD_ON),u.text(`*** RETUR ***
`),u.cmd(o.BOLD_OFF)),r){const _=g(r,n.tanggal);_&&u.cmd(o.CENTER).text(_+`
`)}const R=d||"Terima Kasih!";u.cmd(o.CENTER);for(const _ of R.split(`
`))u.text(_+`
`);return n.notes&&u.cmd(o.CENTER).text(n.notes+`
`),Q(u,h,U),u.toBase64()}function O(n,x,e={}){var A,P;const{charWidth:h=42,feedLines:w=4,compact:r=!1}=e,d=new J;d.cmd(o.INIT_FEED),y(d,h,r),d.cmd(o.CENTER).cmd(o.BOLD_ON).text(`STRUK RETUR
`).cmd(o.BOLD_OFF).cmd(o.LEFT),d.text(p("=",h)),d.text(c("No Retur",": "+(n.nomor_dokumen||"-"),h)+`
`),d.text(c("No Nota",": "+((x==null?void 0:x.nomor_dokumen)||"-"),h)+`
`),d.text(c("Tgl",": "+f(n.tanggal||new Date),h)+`
`),(A=n.created_by)!=null&&A.name&&d.text(c("Kasir",": "+n.created_by.name,h)+`
`),d.text(p("-",h));for(const R of n.details||[]){const I=((P=R.product)==null?void 0:P.nama_produk)||"",K=R.qty||0,D=R.harga_satuan||R.harga_per_base||0,C=Number(K)*Number(D);d.text(I+`
`),d.text(c(`  ${l(K)} x ${i(D)}`,i(C),h)+`
`)}d.text(p("-",h));const U=Number(n.subtotal||0),u=Number(n.pembulatan||0);return U&&d.text(c("Subtotal",i(U),h)+`
`),u&&d.text(c("Pembulatan",i(u),h)+`
`),d.cmd(o.BOLD_ON),d.text(c("TOTAL RETUR",i(n.grand_total),h)+`
`),d.cmd(o.BOLD_OFF),d.text(p("-",h)),d.text(c("Metode Refund","Tunai",h)+`
`),d.text(p("=",h)),d.cmd(o.CENTER).text(`Terima Kasih
`),Q(d,w),d.toBase64()}function $(n,x={}){const{charWidth:e=42,feedLines:h=4,compact:w=!1}=x,r=new J;r.cmd(o.INIT_FEED),y(r,e,w);const U={kas_masuk:"KAS MASUK",kas_keluar:"KAS KELUAR",setor_awal:"SETOR AWAL"}[n.tipe]||"TRANSAKSI KAS";return r.cmd(o.CENTER).cmd(o.BOLD_ON).text(U+`
`).cmd(o.BOLD_OFF).cmd(o.LEFT),r.text(p("=",e)),r.text(c("Terminal",": "+(n.terminal||"-"),e)+`
`),r.text(c("Kasir",": "+(n.kasir||"-"),e)+`
`),r.text(c("Tanggal",": "+(n.date||"-"),e)+`
`),r.text(p("-",e)),r.cmd(o.BOLD_ON),r.text(c("Nominal",i(n.nominal),e)+`
`),r.cmd(o.BOLD_OFF),n.keterangan&&r.text("Ket: "+n.keterangan+`
`),r.text(p("=",e)),Q(r,h),r.toBase64()}function tt(n,x={}){var z,q,ot;const{charWidth:e=42,feedLines:h=4,compact:w=!1}=x,r=new J,d=n.shift||{},U=n.penjualan||{},u=n.payment_breakdown||[],A=n.void||{},P=n.retur||{},R=n.kas||{},I=n.ringkasan||{};r.cmd(o.INIT_FEED),y(r,e,w),r.cmd(o.CENTER).cmd(o.BOLD_ON).text(`LAPORAN SHIFT
`).cmd(o.BOLD_OFF),d.ulid&&r.text(d.ulid+`
`),r.cmd(o.LEFT),r.text(p("=",e)),r.text(c("Terminal",": "+(((z=d.terminal)==null?void 0:z.kode_terminal)||"-"),e)+`
`),r.text(c("Kasir",": "+(((q=d.user)==null?void 0:q.name)||"-"),e)+`
`),r.text(c("Mulai",": "+(d.started_at?f(d.started_at):"-"),e)+`
`),r.text(c("Selesai",": "+(d.ended_at?f(d.ended_at):"-"),e)+`
`);let K="Masih Aktif";d.ended_at&&(K=d.ended_by_force?`Ditutup Paksa oleh ${((ot=d.forced_by_user)==null?void 0:ot.name)||"Admin"}`:"Ditutup Normal"),r.text(c("Status",": "+K,e)+`
`),r.text(p("-",e));const D=Kt(U,i,c,e);if(D.length){r.cmd(o.BOLD_ON).text(D[0]+`
`).cmd(o.BOLD_OFF);for(let b=1;b<D.length-1;b++)r.text(D[b]+`
`);D.length>1&&r.cmd(o.BOLD_ON).text(D[D.length-1]+`
`).cmd(o.BOLD_OFF)}const C=u.reduce((b,F)=>b+Number(F.biaya_tambahan||0),0);C>0&&r.text(c("Biaya Pembayaran",i(C),e)+`
`),r.text(p("-",e));const H=n.serial_units_sold||[];if(H.length){r.cmd(o.BOLD_ON),r.text(c("UNIT SERIAL TERJUAL",`${H.length} unit`,e)+`
`),r.cmd(o.BOLD_OFF);for(const b of H){r.text(c(b.product||"-",i(b.harga),e)+`
`),r.text(`  ${b.kode_internal||"SN "+(b.serial_number||"-")} | ${b.nomor_dokumen||"-"}
`);const F=[];b.kode_internal&&b.serial_number&&F.push(`SN ${b.serial_number}`),b.grade&&F.push(`Grade ${b.grade}`),b.battery_health!==null&&b.battery_health!==void 0&&F.push(`Bat ${b.battery_health}%`),b.account_status&&F.push(`Akun ${b.account_status}`),F.length&&r.text(`  ${F.join(" | ")}
`)}r.text(p("-",e))}r.cmd(o.BOLD_ON).text(`PER METODE BAYAR
`).cmd(o.BOLD_OFF);for(const b of u)r.text(c(`${b.nama} (${b.count}x)`,i(b.total),e)+`
`),b.is_tunai&&Number(n.total_kembalian)>0&&(r.text(c("  Kembalian","-"+i(n.total_kembalian),e)+`
`),r.text(c("  Nett Tunai",i(b.total-n.total_kembalian),e)+`
`)),Number(b.biaya_tambahan)&&r.text(c("  Biaya",i(b.biaya_tambahan),e)+`
`);if(r.text(p("-",e)),r.cmd(o.BOLD_ON),r.text(c("VOID",`${A.jumlah||0} trx`,e)+`
`),r.cmd(o.BOLD_OFF),r.text(c("Nominal Void",i(A.nominal),e)+`
`),r.text(p("-",e)),r.cmd(o.BOLD_ON),r.text(c("RETUR",`${P.jumlah||0} trx`,e)+`
`),r.cmd(o.BOLD_OFF),r.text(c("Total Refund",i(P.total_refund),e)+`
`),Number(P.total_refund)){const b=P.sesi_ini||{},F=P.sesi_sebelumnya||{};r.text(c(`  Sesi Ini (${b.jumlah||0})`,i(b.nominal),e)+`
`),r.text(c(`  Sesi Sblm (${F.jumlah||0})`,i(F.nominal),e)+`
`)}r.text(p("-",e)),r.cmd(o.BOLD_ON).text(`KAS (Uang Fisik di Laci)
`).cmd(o.BOLD_OFF),r.text(c("Setor Awal",i(R.setor_awal),e)+`
`),r.text(c("Penjualan Tunai (net)","+"+i(R.penjualan_tunai),e)+`
`);const Y=Number(R.kas_masuk||0),_=R.kas_masuk_detail||[];r.text(c(`Kas Masuk${_.length?` (${_.length}x)`:""}`,Y?"+"+i(Y):i(0),e)+`
`);for(const b of _)r.text(c(`  ${b.keterangan||"-"}`,"+"+i(b.nominal),e)+`
`);const E=Number(R.kas_keluar||0),S=R.kas_keluar_detail||[];r.text(c(`Kas Keluar${S.length?` (${S.length}x)`:""}`,E?"-"+i(E):i(0),e)+`
`);for(const b of S)r.text(c(`  ${b.keterangan||"-"}`,"-"+i(b.nominal),e)+`
`);const V=Number(R.refund_tunai||0);if(r.text(c("Refund Retur (Cash)",V?"-"+i(V):i(0),e)+`
`),r.text(p("-",e)),r.cmd(o.BOLD_ON),r.text(c("Saldo Kas",i(R.saldo),e)+`
`),r.cmd(o.BOLD_OFF),r.text(p("-",e)),d.ended_at){if(r.cmd(o.BOLD_ON).text(`REKONSILIASI KAS
`).cmd(o.BOLD_OFF),r.text(c("Saldo Sistem",i(d.saldo_system),e)+`
`),d.saldo_fisik!==null&&d.saldo_fisik!==void 0){r.text(c("Uang Fisik di Laci",i(d.saldo_fisik),e)+`
`);const b=Number(d.selisih||0),F=b===0?"Cocok":b>0?"Lebih":"Kurang",gt=(b>0?"+":"")+i(b)+" ("+F+")";r.cmd(o.BOLD_ON),r.text(c("Selisih",gt,e)+`
`),r.cmd(o.BOLD_OFF)}else r.text(c("Uang Fisik di Laci","Belum di-input",e)+`
`);d.closing_notes&&r.text("Catatan: "+d.closing_notes+`
`),r.text(p("-",e))}return r.cmd(o.BOLD_ON).text(`RINGKASAN
`).cmd(o.BOLD_OFF),r.text(c("Total Tunai",i(I.total_tunai),e)+`
`),r.text(c("Total Non-Tunai",i(I.total_non_tunai),e)+`
`),r.text(p("=",e)),w?(r.cmd(o.BOLD_ON),r.text(c("TOTAL SEMUA",i(I.total_semua),e)+`
`),r.cmd(o.BOLD_OFF)):(r.cmd(o.DOUBLE),r.text(c("TOTAL SEMUA",i(I.total_semua),e/2|0)+`
`),r.cmd(o.NORMAL).cmd(o.BOLD_OFF)),r.text(p("=",e)),Q(r,h),r.toBase64()}function et(n={}){const{charWidth:x=42}=n,e=new J;return e.cmd(o.INIT_FEED).cmd(o.CENTER).cmd(o.DOUBLE),e.text(`TEST PRINT
`),e.cmd(o.NORMAL),e.text(`POSIP Thermal Print
`),e.text(p("=",x)),e.cmd(o.LEFT),e.text(`Printer is working correctly!
`),e.text(`Paper width: ${x} chars
`),e.text(p("-",x)),e.text(c("LEFT ALIGN","RIGHT ALIGN",x)+`
`),e.text(p("-",x)),e.cmd(o.CENTER).text(`END OF TEST
`),Q(e,4),e.toBase64()}function v(){const n=new J;return n.cmd(o.INIT).cmd(o.DRAWER_2),n.toBase64()}return{buildReceipt:G,buildReturReceipt:O,buildCashReceipt:$,buildShiftReport:tt,buildTestPage:et,drawerBytes:v}}export{Jt as a,wt as b,Vt as i,qt as u};
