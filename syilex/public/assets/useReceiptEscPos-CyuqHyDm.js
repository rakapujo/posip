import{A as z,K as G}from"./vue-core-Dr_IIeMI.js";import{u as bt}from"./useFormatters-BPpoeryP.js";import{u as ht}from"./useReceiptPdf-DJbGJBHM.js";import{a as xt}from"./index-BS31zwIp.js";const X="posip-thermal-printer",ot=new Set(["bluetooth","serial","usb"]);function pt(t){if(!t||typeof t!="object")return null;const a=t.kind;if(!ot.has(a))return null;const o={kind:a},u=t.terminalUlid,m=t.label;return typeof u=="string"&&u.trim()&&(o.terminalUlid=u.trim()),typeof m=="string"&&m.trim()&&(o.label=m.trim()),o}function v(){try{const t=localStorage.getItem(X);return t?pt(JSON.parse(t)):null}catch{return null}}function kt(t){if(!(t!=null&&t.kind)||!ot.has(t.kind))throw new Error("Invalid printer kind");const a={kind:t.kind};t.terminalUlid&&(a.terminalUlid=t.terminalUlid),t.label&&(a.label=t.label),localStorage.setItem(X,JSON.stringify(a))}function yt(){try{localStorage.removeItem(X)}catch{}}function vt(t){const a=v();return a?!t||!a.terminalUlid?!0:a.terminalUlid===t:!1}function Ot(t){if(typeof t!="string"||!t.length)return new Uint8Array(0);const a=t.trim();if(!a.length)return new Uint8Array(0);const o=atob(a),u=new Uint8Array(o.length);for(let m=0;m<o.length;m++)u[m]=o.charCodeAt(m);return u}const Nt=[27,112,0,25,25],gt=[29,86,1];let N=null;const Lt=[6384,65504,65280,"49535343-fe7d-4ae5-8fa9-9fafd205e455","e7810a71-73ae-499d-8c15-faa9aef0c3f2","6e400001-b5a3-f393-e0a9-e50e24dcca9e"];function tt(t=typeof navigator<"u"?navigator:{}){return t}function Q(){return N}function et(t){const a=tt(t);return{bluetooth:!!a.bluetooth,serial:!!a.serial,usb:!!a.usb}}function st(t){const a=et(t);return a.bluetooth||a.serial||a.usb}function Bt(t,a){a==null||a(),N&&(N.disconnect().catch(()=>{}),N=null)}async function ct(t){const o=await(await t.gatt.connect()).getPrimaryServices();let u=null;for(const h of o){const y=await h.getCharacteristics();for(const c of y)if(c.properties.write||c.properties.writeWithoutResponse){u=c;break}if(u)break}if(!u)throw new Error("Karakteristik tulis printer Bluetooth tidak ditemukan.");const m=u.properties.writeWithoutResponse&&!u.properties.write,b=u;return{kind:"bluetooth",label:t.name||"Printer Bluetooth",async write(h){for(let c=0;c<h.length;c+=180){const $=h.slice(c,c+180);m&&b.writeValueWithoutResponse?await b.writeValueWithoutResponse($):await b.writeValue($),await new Promise(C=>setTimeout(C,18))}},async disconnect(){var h;try{(h=t.gatt)==null||h.disconnect()}catch{}}}}function lt(t){return{kind:"serial",label:"Printer USB (Serial)",async write(a){const o=t.writable.getWriter();try{await o.write(a)}finally{o.releaseLock()}},async disconnect(){try{await t.close()}catch{}}}}async function ut(t){await t.open(),t.configuration===null&&await t.selectConfiguration(1);let a=0,o=1;for(const u of t.configuration.interfaces)for(const m of u.alternates)if(m.interfaceClass===7||m.interfaceClass===255){a=u.interfaceNumber;const b=m.endpoints.find(h=>h.direction==="out");b&&(o=b.endpointNumber)}return await t.claimInterface(a),{kind:"usb",label:t.productName||"Printer USB",async write(u){for(let b=0;b<u.length;b+=4096)await t.transferOut(o,u.slice(b,b+4096))},async disconnect(){try{await t.close()}catch{}}}}async function Tt(t){if(!t.bluetooth)throw new Error("Browser tidak mendukung Web Bluetooth (pakai Chrome/Edge).");const a=await t.bluetooth.requestDevice({acceptAllDevices:!0,optionalServices:Lt});return N=await ct(a),N}async function Et(t){if(!t.serial)throw new Error("Browser tidak mendukung Web Serial (pakai Chrome/Edge desktop).");const a=await t.serial.requestPort();return await a.open({baudRate:9600}),N=lt(a),N}async function Dt(t){if(!t.usb)throw new Error("Browser tidak mendukung WebUSB.");const a=await t.usb.requestDevice({filters:[{classCode:7},{classCode:255}]});return N=await ut(a),N}async function Ft(t,a){const o=tt(a);return t==="bluetooth"?Tt(o):t==="serial"?Et(o):Dt(o)}async function mt(t,a){var u,m,b;if(N)return N;const o=tt(a);try{if(t==="serial"&&((u=o.serial)!=null&&u.getPorts)){const h=await o.serial.getPorts();if(h.length){try{await h[0].open({baudRate:9600})}catch{}return N=lt(h[0]),N}}if(t==="bluetooth"&&((m=o.bluetooth)!=null&&m.getDevices)){const h=await o.bluetooth.getDevices();if(h.length)return N=await ct(h[0]),N}if(t==="usb"&&((b=o.usb)!=null&&b.getDevices)){const h=await o.usb.getDevices();if(h.length)return N=await ut(h[0]),N}}catch{}return null}async function Rt(t,a={}){const{writeFn:o,reconnectFn:u}=a;if(!t)return{ok:!1,error:"Data cetak kosong"};let m;try{m=Ot(t)}catch{return{ok:!1,error:"Data base64 tidak valid"}}if(!m.length)return{ok:!1,error:"Payload ESC/POS kosong"};const b=v();let h=Q();if(h||(h=await(u||(()=>mt((b==null?void 0:b.kind)??null)))()),h)try{return await(o||(c=>h.write(c)))(m),{ok:!0}}catch(y){return{ok:!1,error:(y==null?void 0:y.message)||"Gagal mengirim ke printer"}}return b!=null&&b.kind?{ok:!1,needPicker:!0,error:"Printer perlu disambungkan ulang"}:{ok:!1,needPicker:!0,error:"Printer thermal belum dipasangkan"}}async function St(){return!!(Q()||v())}function wt(){const t=z(Q()),a=z(null),o=G(()=>st()),u=G(()=>et()),m=G(()=>{var O,g;return((O=t.value)==null?void 0:O.label)||((g=v())==null?void 0:g.label)||null}),b=G(()=>!!t.value);function h(){t.value=Q()}async function y(O,{terminalUlid:g,label:L}={}){a.value=null;try{const R=await Ft(O);return kt({kind:O,terminalUlid:g,label:L||R.label}),t.value=R,R}catch(R){throw a.value=(R==null?void 0:R.message)||"Gagal menghubungkan printer",R}}async function c(){a.value=null;const O=v(),g=await mt((O==null?void 0:O.kind)??null);return t.value=g,g}function $(){Bt(v(),yt),t.value=null,a.value=null}async function C(O){const g=t.value||await c();if(!g)throw new Error("Printer belum dipasangkan");await g.write(O)}return{connection:t,lastError:a,supported:o,support:u,printerLabel:m,isConnected:b,pick:y,reconnect:c,forget:$,write:C,syncConnection:h}}function Ct(){const t=wt(),a=z(!1),o=z(!1),u=z(null),m=G(()=>st()),b=G(()=>et()),h=G(()=>{var L;return t.printerLabel.value||((L=v())==null?void 0:L.label)||null});async function y(){const L=await St();return a.value=L,L}function c(){var L;return!!(Q()||(L=v())!=null&&L.kind)}async function $(L,R){return t.pick(L,R)}async function C(){return t.reconnect()}function O(){t.forget()}async function g(L,R={}){o.value=!0,u.value=null;try{const j=await Rt(L,{writeFn:Z=>t.write(Z),reconnectFn:()=>t.reconnect()});return j.ok||(u.value=j.error||"Cetak gagal"),{success:j.ok,needPicker:j.needPicker||!1,message:j.error}}finally{o.value=!1}}return{isAvailable:a,busy:o,error:u,supported:m,support:b,printerLabel:h,checkStatus:y,isReadyToThermal:c,pick:$,reconnect:C,forget:O,printRaw:g,transport:t}}function At(t,a,o,u){const m=[];if(m.push(o("PENJUALAN",`${t.jumlah_transaksi||0} trx`,u)),m.push(o("Penjualan Kotor",a(t.penjualan_kotor),u)),Number(t.diskon_item)>0){m.push(o("Diskon Item","-"+a(t.diskon_item),u));for(let b=1;b<=5;b++){const h=Number(t[`diskon_line_${b}`]||0);if(h>0){const y=b===5?" (Manual)":"";m.push(o(`  Line ${b}${y}`,"-"+a(h),u))}}}else m.push(o("Diskon Item",a(0),u));return Number(t.diskon_nota)>0?(m.push(o("Diskon Nota","-"+a(t.diskon_nota),u)),Number(t.diskon_nota_l1)>0&&m.push(o("  Tipe Customer (L1)","-"+a(t.diskon_nota_l1),u)),Number(t.diskon_nota_l2)>0&&m.push(o("  Kategori Customer (L2)","-"+a(t.diskon_nota_l2),u)),Number(t.diskon_nota_l3)>0&&m.push(o("  Manual Kasir (L3)","-"+a(t.diskon_nota_l3),u))):m.push(o("Diskon Nota",a(0),u)),m.push(o("Penjualan Bersih",a(t.penjualan_bersih),u)),m.push(o("Biaya Kirim",a(t.biaya_kirim),u)),m.push(o("Biaya Lain",a(t.biaya_lain),u)),t.pajak_nama?m.push(o(`Pajak (${t.pajak_nama} ${t.pajak_persen}%)`,a(t.pajak_nominal),u)):m.push(o("Pajak",a(t.pajak_nominal),u)),m.push(o("Pembulatan",a(t.pembulatan),u)),m.push(o("OMZET",a(t.omzet),u)),m}function Pt(t=4,a=!1){const o=[];a&&o.push(...Nt);const u=Math.min(Math.max(t,0),10);return u>0&&o.push(27,100,u),o.push(...gt),new Uint8Array(o)}const r={INIT:[27,64],INIT_FEED:[27,64,10],CENTER:[27,97,1],LEFT:[27,97,0],BOLD_ON:[27,69,1],BOLD_OFF:[27,69,0],DOUBLE:[27,33,48],NORMAL:[27,33,0],DRAWER_2:[27,112,0,25,25]};class V{constructor(){this._parts=[]}cmd(a){return this._parts.push(new Uint8Array(a)),this}text(a){const o=new Uint8Array(a.length);for(let u=0;u<a.length;u++){const m=a.charCodeAt(u);o[u]=m<128?m:63}return this._parts.push(o),this}toBytes(){let a=0;for(const m of this._parts)a+=m.length;const o=new Uint8Array(a);let u=0;for(const m of this._parts)o.set(m,u),u+=m.length;return o}toBase64(){const a=this.toBytes();let o="";for(let u=0;u<a.length;u++)o+=String.fromCharCode(a[u]);return btoa(o)}}function k(t,a){return t.repeat(a)+`
`}function s(t,a,o){const u=a.length,m=o-u-1;return(t.length>m?t.slice(0,m):t+" ".repeat(Math.max(0,m-t.length)))+" "+a}function rt(t,a){if(t.length<=a)return[t];const o=(t.match(/^\s*/)||[""])[0],u=t.trim().split(/\s+/),m=[];let b=o;for(const h of u){const y=b.trim()===""?o+h:b+" "+h;y.length>a&&b.trim()!==""?(m.push(b),b=o+h):b=y}return b.trim()!==""&&m.push(b),m.length?m:[t]}function J(t,a,o=!1){t.cmd(Array.from(Pt(a,o)))}function jt(){const{formatCurrency:t,formatNumber:a,formatQty:o,formatPercent:u,formatDateTime:m}=bt(),b=xt(),{buildReturPolicyText:h}=ht();function y(e){return e==null?"0":a(Math.abs(Number(e)))}function c(e){if(e==null)return"0";const p=Number(e),n=y(p);return p<0?`-${n}`:n}function $(e){const p=[];return e.kode_internal&&p.push(e.kode_internal),p.push(`SN ${e.serial_number||"-"}`),e.grade&&p.push(e.grade),e.battery_health!==null&&e.battery_health!==void 0&&e.battery_health!==""?p.push(`Bat ${e.battery_health}%${e.battery_condition?" "+e.battery_condition:""}`):e.battery_condition&&p.push(`Bat ${e.battery_condition}`),e.battery_cycle_count!==null&&e.battery_cycle_count!==void 0&&e.battery_cycle_count!==""&&p.push(`Cyc ${e.battery_cycle_count}`),e.account_status&&p.push(e.account_status),{main:p.join(" . "),catatan:e.catatan||""}}function C(e){const p=[];for(let n=1;n<=5;n++){const x=e[`diskon_${n}_tipe`],B=Number(e[`diskon_${n}_nilai`]||0);x==="none"||B===0||p.push(x==="percent"?u(B):t(B))}return p.join("+")}function O(e,p,n){if(!p||!n)return e;const x=p==="percent"?u(n):c(n);return`${e} (${x})`}function g(e,p,n){const x=b.store;if(e.cmd(r.CENTER),n?e.cmd(r.BOLD_ON).text((x.name||"POSIP")+`
`).cmd(r.BOLD_OFF):e.cmd(r.DOUBLE).text((x.name||"POSIP")+`
`).cmd(r.NORMAL),x.address)for(const B of String(x.address).split(/\r?\n/))B.trim()&&e.text(B+`
`);x.phone&&e.text("Telp: "+x.phone+`
`),x.email&&e.text("Email: "+x.email+`
`),x.npwp&&e.text("NPWP: "+x.npwp+`
`),e.text(k("=",p))}function L(e,p={}){var P,U,w,M,W,Y;const{charWidth:n=42,feedLines:x=4,compact:B=!1,returPolicy:i=null,footer:_=null,openDrawer:I=!1}=p,l=new V;l.cmd(r.INIT_FEED),g(l,n,B),l.cmd(r.LEFT),l.text(s("No",": "+(e.nomor_dokumen||"-"),n)+`
`),l.text(s("Tgl",": "+m(e.tanggal),n)+`
`),(P=e.created_by)!=null&&P.name&&l.text(s("Kasir",": "+e.created_by.name,n)+`
`);const A=(U=e.customer)==null?void 0:U.nama;A&&A!=="Walk-in"&&l.text(s("Cust",": "+A,n)+`
`),l.text(k("-",n));for(const d of e.details||[]){l.text((((w=d.product)==null?void 0:w.nama_produk)||"")+`
`);const T=Number(d.qty||0)*Number(d.harga_satuan||0);if(l.text(s(`  ${o(d.qty)} ${d.unit||""} x ${c(d.harga_satuan)}`,c(T),n)+`
`),Number(d.diskon_total)>0){const E=C(d);l.text(s(`    ${E}`,"-"+c(d.diskon_total),n)+`
`)}if((M=d.serial_units)!=null&&M.length)for(const E of d.serial_units){const{main:H,catatan:K}=$(E);for(const q of rt("  "+H,n))l.text(q+`
`);if(K)for(const q of rt("    Cat: "+K,n))l.text(q+`
`)}}l.text(k("-",n)),l.text(s("Subtotal",c(e.subtotal),n)+`
`);for(let d=1;d<=3;d++){const T=Number(e[`diskon_nota_${d}_hasil`]||0);if(T>0){const E=e[`_disc_label_${d}`]||e[`diskon_nota_${d}_label`],H=d===3?"Disc Manual":`Disc ${d}`,K=E?O(E,e[`diskon_nota_${d}_tipe`],e[`diskon_nota_${d}_nilai`]):O(H,e[`diskon_nota_${d}_tipe`],e[`diskon_nota_${d}_nilai`]);l.text(s("  "+K,"-"+c(T),n)+`
`)}}if(Number(e.total_diskon)>0&&l.text(s("Total",c(e.total_setelah_diskon),n)+`
`),Number(e.biaya_kirim_hasil)>0){const d=O("Biaya Kirim",e.biaya_kirim_tipe,e.biaya_kirim_nilai);l.text(s(d,c(e.biaya_kirim_hasil),n)+`
`)}if(Number(e.biaya_lain_hasil)>0){const d=O("Biaya Lain",e.biaya_lain_tipe,e.biaya_lain_nilai);l.text(s(d,c(e.biaya_lain_hasil),n)+`
`)}Number(e.pajak_nominal)>0&&(l.text(s("DPP",c(e.dpp),n)+`
`),l.text(s(`${e.pajak_nama||"PPN"} ${e.pajak_persen}%`,c(e.pajak_nominal),n)+`
`)),Number(e.pembulatan)&&l.text(s("Pembulatan",c(e.pembulatan),n)+`
`),l.text(k("-",n)),l.cmd(r.BOLD_ON),l.text(s("GRAND TOTAL",c(e.grand_total),n)+`
`),l.cmd(r.BOLD_OFF),l.text(k("-",n));for(const d of e.payments||[])l.text(s(((W=d.metode_pembayaran)==null?void 0:W.nama_pembayaran)||"",c(d.nominal),n)+`
`),Number(d.biaya_tambahan)>0&&l.text(s("  Biaya",c(d.biaya_tambahan),n)+`
`);Number(e.total_bayar)&&(l.cmd(r.BOLD_ON),l.text(s("Total Bayar",c(e.total_bayar),n)+`
`),l.cmd(r.BOLD_OFF)),Number(e.kembalian)>0&&(l.cmd(r.BOLD_ON),l.text(s("Kembali",c(e.kembalian),n)+`
`),l.cmd(r.BOLD_OFF)),l.text(k("=",n));const S=e.returns||[];if(S.length>0){l.cmd(r.BOLD_ON).text(`RIWAYAT RETUR
`).cmd(r.BOLD_OFF);for(const T of S){l.text(s(T.nomor_dokumen||"","Tunai",n)+`
`),l.text("  "+m(T.tanggal)+`
`);for(const E of T.details||[])l.text(s(`  ${((Y=E.product)==null?void 0:Y.nama_produk)||""} x${o(E.qty)}`,`@ ${c(E.harga_satuan)}`,n)+`
`);Number(T.pembulatan)&&l.text(s("  Pembulatan",c(T.pembulatan),n)+`
`),l.cmd(r.BOLD_ON).text(s("  Total Retur",c(T.grand_total),n)+`
`).cmd(r.BOLD_OFF)}l.text(k("-",n)),l.cmd(r.BOLD_ON).text(`RINGKASAN
`).cmd(r.BOLD_OFF),l.text(s("Pembayaran Asli",c(e.grand_total),n)+`
`),(Number(e.biaya_kirim_hasil)>0||Number(e.biaya_lain_hasil)>0)&&(l.text(`Tidak Termasuk Retur:
`),Number(e.biaya_kirim_hasil)>0&&l.text(s("  Biaya Kirim",c(e.biaya_kirim_hasil),n)+`
`),Number(e.biaya_lain_hasil)>0&&l.text(s("  Biaya Lain",c(e.biaya_lain_hasil),n)+`
`));const d=S.reduce((T,E)=>T+Number(E.grand_total||0),0);l.text(s("Total Semua Retur",c(d),n)+`
`),l.cmd(r.BOLD_ON),l.text(s("NILAI BERSIH",c(Number(e.grand_total)-d),n)+`
`),l.cmd(r.BOLD_OFF),l.text(`(Pembayaran - Retur)
`),l.text(k("=",n))}if(e.status==="voided"?(l.cmd(r.CENTER).cmd(r.BOLD_ON),B||l.cmd(r.DOUBLE),l.text(`*** VOID ***
`),l.cmd(r.NORMAL).cmd(r.BOLD_OFF)):S.length>0&&(l.cmd(r.CENTER).cmd(r.BOLD_ON),l.text(`*** RETUR ***
`),l.cmd(r.BOLD_OFF)),i){const d=h(i,e.tanggal);d&&l.cmd(r.CENTER).text(d+`
`)}const D=_||"Terima Kasih!";l.cmd(r.CENTER);for(const d of D.split(`
`))l.text(d+`
`);return e.notes&&l.cmd(r.CENTER).text(e.notes+`
`),J(l,x,I),l.toBase64()}function R(e,p,n={}){var A,S;const{charWidth:x=42,feedLines:B=4,compact:i=!1}=n,_=new V;_.cmd(r.INIT_FEED),g(_,x,i),_.cmd(r.CENTER).cmd(r.BOLD_ON).text(`STRUK RETUR
`).cmd(r.BOLD_OFF).cmd(r.LEFT),_.text(k("=",x)),_.text(s("No Retur",": "+(e.nomor_dokumen||"-"),x)+`
`),_.text(s("No Nota",": "+((p==null?void 0:p.nomor_dokumen)||"-"),x)+`
`),_.text(s("Tgl",": "+m(e.tanggal||new Date),x)+`
`),(A=e.created_by)!=null&&A.name&&_.text(s("Kasir",": "+e.created_by.name,x)+`
`),_.text(k("-",x));for(const D of e.details||[]){const P=((S=D.product)==null?void 0:S.nama_produk)||"",U=D.qty||0,w=D.harga_satuan||D.harga_per_base||0,M=Number(U)*Number(w);_.text(P+`
`),_.text(s(`  ${o(U)} x ${c(w)}`,c(M),x)+`
`)}_.text(k("-",x));const I=Number(e.subtotal||0),l=Number(e.pembulatan||0);return I&&_.text(s("Subtotal",c(I),x)+`
`),l&&_.text(s("Pembulatan",c(l),x)+`
`),_.cmd(r.BOLD_ON),_.text(s("TOTAL RETUR",c(e.grand_total),x)+`
`),_.cmd(r.BOLD_OFF),_.text(k("-",x)),_.text(s("Metode Refund","Tunai",x)+`
`),_.text(k("=",x)),_.cmd(r.CENTER).text(`Terima Kasih
`),J(_,B),_.toBase64()}function j(e,p={}){const{charWidth:n=42,feedLines:x=4,compact:B=!1}=p,i=new V;i.cmd(r.INIT_FEED),g(i,n,B);const I={kas_masuk:"KAS MASUK",kas_keluar:"KAS KELUAR",setor_awal:"SETOR AWAL"}[e.tipe]||"TRANSAKSI KAS";return i.cmd(r.CENTER).cmd(r.BOLD_ON).text(I+`
`).cmd(r.BOLD_OFF).cmd(r.LEFT),i.text(k("=",n)),i.text(s("Terminal",": "+(e.terminal||"-"),n)+`
`),i.text(s("Kasir",": "+(e.kasir||"-"),n)+`
`),i.text(s("Tanggal",": "+(e.date||"-"),n)+`
`),i.text(k("-",n)),i.cmd(r.BOLD_ON),i.text(s("Nominal",c(e.nominal),n)+`
`),i.cmd(r.BOLD_OFF),e.keterangan&&i.text("Ket: "+e.keterangan+`
`),i.text(k("=",n)),J(i,x),i.toBase64()}function Z(e,p={}){var nt,it,at;const{charWidth:n=42,feedLines:x=4,compact:B=!1}=p,i=new V,_=e.shift||{},I=e.penjualan||{},l=e.payment_breakdown||[],A=e.void||{},S=e.retur||{},D=e.kas||{},P=e.ringkasan||{};i.cmd(r.INIT_FEED),g(i,n,B),i.cmd(r.CENTER).cmd(r.BOLD_ON).text(`LAPORAN SHIFT
`).cmd(r.BOLD_OFF),_.ulid&&i.text(_.ulid+`
`),i.cmd(r.LEFT),i.text(k("=",n)),i.text(s("Terminal",": "+(((nt=_.terminal)==null?void 0:nt.kode_terminal)||"-"),n)+`
`),i.text(s("Kasir",": "+(((it=_.user)==null?void 0:it.name)||"-"),n)+`
`),i.text(s("Mulai",": "+(_.started_at?m(_.started_at):"-"),n)+`
`),i.text(s("Selesai",": "+(_.ended_at?m(_.ended_at):"-"),n)+`
`);let U="Masih Aktif";_.ended_at&&(U=_.ended_by_force?`Ditutup Paksa oleh ${((at=_.forced_by_user)==null?void 0:at.name)||"Admin"}`:"Ditutup Normal"),i.text(s("Status",": "+U,n)+`
`),i.text(k("-",n));const w=At(I,c,s,n),M=w.length?w[w.length-1]:null,W=w.slice(0,-1);if(W.length){i.cmd(r.BOLD_ON).text(W[0]+`
`).cmd(r.BOLD_OFF);for(let f=1;f<W.length;f++)i.text(W[f]+`
`)}const Y=l.reduce((f,F)=>f+Number(F.biaya_tambahan||0),0);Y>0&&i.text(s("Biaya Pembayaran",c(Y),n)+`
`),M&&i.cmd(r.BOLD_ON).text(M+`
`).cmd(r.BOLD_OFF),i.text(k("-",n));const d=e.serial_units_sold||[];if(d.length){i.cmd(r.BOLD_ON),i.text(s("UNIT SERIAL TERJUAL",`${d.length} unit`,n)+`
`),i.cmd(r.BOLD_OFF);for(const f of d){i.text(s(f.product||"-",c(f.harga),n)+`
`),i.text(`  ${f.kode_internal||"SN "+(f.serial_number||"-")} | ${f.nomor_dokumen||"-"}
`);const F=[];f.kode_internal&&f.serial_number&&F.push(`SN ${f.serial_number}`),f.grade&&F.push(`Grade ${f.grade}`),f.battery_health!==null&&f.battery_health!==void 0&&F.push(`Bat ${f.battery_health}%`),f.battery_cycle_count!==null&&f.battery_cycle_count!==void 0&&F.push(`Cyc ${f.battery_cycle_count}`),f.account_status&&F.push(`Akun ${f.account_status}`),f.catatan&&F.push(`Cat ${f.catatan}`),F.length&&i.text(`  ${F.join(" | ")}
`)}i.text(k("-",n))}i.cmd(r.BOLD_ON).text(`PER METODE BAYAR
`).cmd(r.BOLD_OFF);for(const f of l)i.text(s(`${f.nama} (${f.count}x)`,c(f.total),n)+`
`),f.is_tunai&&Number(e.total_kembalian)>0&&(i.text(s("  Kembalian","-"+c(e.total_kembalian),n)+`
`),i.text(s("  Nett Tunai",c(f.total-e.total_kembalian),n)+`
`)),Number(f.biaya_tambahan)&&i.text(s("  Biaya",c(f.biaya_tambahan),n)+`
`);if(i.text(k("-",n)),i.cmd(r.BOLD_ON),i.text(s("VOID",`${A.jumlah||0} trx`,n)+`
`),i.cmd(r.BOLD_OFF),i.text(s("Nominal Void",c(A.nominal),n)+`
`),i.text(k("-",n)),i.cmd(r.BOLD_ON),i.text(s("RETUR",`${S.jumlah||0} trx`,n)+`
`),i.cmd(r.BOLD_OFF),i.text(s("Total Refund",c(S.total_refund),n)+`
`),Number(S.total_refund)){const f=S.sesi_ini||{},F=S.sesi_sebelumnya||{};i.text(s(`  Sesi Ini (${f.jumlah||0})`,c(f.nominal),n)+`
`),i.text(s(`  Sesi Sblm (${F.jumlah||0})`,c(F.nominal),n)+`
`)}i.text(k("-",n)),i.cmd(r.BOLD_ON).text(`KAS (Uang Fisik di Laci)
`).cmd(r.BOLD_OFF),i.text(s("Setor Awal",c(D.setor_awal),n)+`
`),i.text(s("Penjualan Tunai (net)","+"+c(D.penjualan_tunai),n)+`
`);const T=Number(D.kas_masuk||0),E=D.kas_masuk_detail||[];i.text(s(`Kas Masuk${E.length?` (${E.length}x)`:""}`,T?"+"+c(T):c(0),n)+`
`);for(const f of E)i.text(s(`  ${f.keterangan||"-"}`,"+"+c(f.nominal),n)+`
`);const H=Number(D.kas_keluar||0),K=D.kas_keluar_detail||[];i.text(s(`Kas Keluar${K.length?` (${K.length}x)`:""}`,H?"-"+c(H):c(0),n)+`
`);for(const f of K)i.text(s(`  ${f.keterangan||"-"}`,"-"+c(f.nominal),n)+`
`);const q=Number(D.refund_tunai||0);if(i.text(s("Refund Retur (Cash)",q?"-"+c(q):c(0),n)+`
`),i.text(k("-",n)),i.cmd(r.BOLD_ON),i.text(s("Saldo Kas",c(D.saldo),n)+`
`),i.cmd(r.BOLD_OFF),i.text(k("-",n)),_.ended_at){if(i.cmd(r.BOLD_ON).text(`REKONSILIASI KAS
`).cmd(r.BOLD_OFF),i.text(s("Saldo Sistem",c(_.saldo_system),n)+`
`),_.saldo_fisik!==null&&_.saldo_fisik!==void 0){i.text(s("Uang Fisik di Laci",c(_.saldo_fisik),n)+`
`);const f=Number(_.selisih||0),F=f===0?"Cocok":f>0?"Lebih":"Kurang",dt=(f>0?"+":"")+c(f)+" ("+F+")";i.cmd(r.BOLD_ON),i.text(s("Selisih",dt,n)+`
`),i.cmd(r.BOLD_OFF)}else i.text(s("Uang Fisik di Laci","Belum di-input",n)+`
`);_.closing_notes&&i.text("Catatan: "+_.closing_notes+`
`),i.text(k("-",n))}return i.cmd(r.BOLD_ON).text(`RINGKASAN
`).cmd(r.BOLD_OFF),i.text(s("Total Tunai",c(P.total_tunai),n)+`
`),i.text(s("Total Non-Tunai",c(P.total_non_tunai),n)+`
`),i.text(k("=",n)),B?(i.cmd(r.BOLD_ON),i.text(s("TOTAL SEMUA",c(P.total_semua),n)+`
`),i.cmd(r.BOLD_OFF)):(i.cmd(r.DOUBLE),i.text(s("TOTAL SEMUA",c(P.total_semua),n/2|0)+`
`),i.cmd(r.NORMAL).cmd(r.BOLD_OFF)),i.text(k("=",n)),J(i,x),i.toBase64()}function ft(e={}){const{charWidth:p=42}=e,n=new V;return n.cmd(r.INIT_FEED).cmd(r.CENTER).cmd(r.DOUBLE),n.text(`TEST PRINT
`),n.cmd(r.NORMAL),n.text(`POSIP Thermal Print
`),n.text(k("=",p)),n.cmd(r.LEFT),n.text(`Printer is working correctly!
`),n.text(`Paper width: ${p} chars
`),n.text(k("-",p)),n.text(s("LEFT ALIGN","RIGHT ALIGN",p)+`
`),n.text(k("-",p)),n.cmd(r.CENTER).text(`END OF TEST
`),J(n,4),n.toBase64()}function _t(){const e=new V;return e.cmd(r.INIT).cmd(r.DRAWER_2),e.toBase64()}return{buildReceipt:L,buildReturReceipt:R,buildCashReceipt:j,buildShiftReport:Z,buildTestPage:ft,drawerBytes:_t}}export{jt as a,vt as i,Ct as u};
