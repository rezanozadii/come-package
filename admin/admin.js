(() => {
'use strict';const $=(s,r=document)=>r.querySelector(s),$$=(s,r=document)=>[...r.querySelectorAll(s)];
const form=$('#mainForm');if(!form)return;
let dirty=false,submitting=false;
function markDirty(){dirty=true;$('#dirty').hidden=false;}
function show(id) {
  const requested = id || location.hash.slice(1) || new URLSearchParams(location.search).get('tab') || 'p1';
  const panel = document.getElementById('panel-' + requested) || $('#panel-p1');
  const active = panel.id.slice(6);
  $$('.panel').forEach(p => { p.hidden = p !== panel; });
  $$('[data-tab]').forEach(a => {
    a.classList.toggle('on', a.dataset.tab === active);
    if(a.dataset.tab === active)a.setAttribute('aria-current','page');else a.removeAttribute('aria-current');
  });
  $('#saveBar').hidden = panel.dataset.form !== '1';
  if(panel.dataset.form === '1')$('#tabField').value = active;
}
$$('[data-tab]').forEach(a => a.addEventListener('click', e => {
  if(e.ctrlKey || e.metaKey || e.shiftKey || e.altKey)return;
  e.preventDefault();
  history.pushState(null, '', a.href);
  show(a.dataset.tab);
}));
addEventListener('hashchange',()=>show());
addEventListener('popstate',()=>show());
show();
$$('[data-fa-date]').forEach(t=>{const d=new Date(t.dateTime);if(!isNaN(d))t.textContent=d.toLocaleString('fa-IR',{dateStyle:'medium',timeStyle:'short'});});
function options(){return $$('.card-row').map((r,i)=>({key:r.dataset.key,title:(i+1)+' · '+($('[data-card-title]',r).value||'خاطرهٔ جدید')}));}
function sync(){
  const rows=$$('.card-row'), values=options();
  rows.forEach((row,i)=>{
    $('[data-card-number]',row).textContent=i+1;
    const position=$('[data-card-position]',row);
    position.value=i+2;position.max=rows.length+1;
    $('[data-book-position]',row).textContent='ورق '+(i+2).toLocaleString('fa-IR')+' دفتر';
  });
  $$('[data-page-select]').forEach(select=>{
    const value=select.value;
    select.replaceChildren(...values.map(v=>new Option(v.title,v.key)));
    if(values.some(v=>v.key===value))select.value=value;
  });
}
let newPageCounter=0;
function newPageKey(){
  const bytes=new Uint32Array(2);
  if(window.crypto?.getRandomValues)window.crypto.getRandomValues(bytes);
  return 'n'+Date.now().toString(36)+(++newPageCounter).toString(36)+Array.from(bytes,n=>n.toString(36)).join('');
}
document.addEventListener('click',e=>{
  const button=e.target.closest('[data-act]');if(!button)return;
  const row=button.closest('.row');
  switch(button.dataset.act){
    case 'add-card':{
      const tpl=document.createElement('template');
      tpl.innerHTML=$('#cardTpl').innerHTML.split('__KEY__').join(newPageKey());
      $('#cardList').append(tpl.content);
      $('[data-card-title]',$('#cardList').lastElementChild).focus();
      break;
    }
    case 'rm':
      if($$('.card-row').length===1){alert('حداقل یک صفحه را نگه دار.');return;}
      row.remove();break;
    case 'up':row.previousElementSibling?.before(row);break;
    case 'down':row.nextElementSibling?.after(row);break;
    default:return;
  }
  sync();markDirty();
});
form.addEventListener('change',e=>{
  if(!e.target.matches('[data-card-position]'))return;
  const row=e.target.closest('.card-row'), all=$$('.card-row');
  const target=Math.max(0,Math.min(all.length-1,(parseInt(e.target.value,10)||2)-2));
  const others=all.filter(item=>item!==row);
  if(others[target])others[target].before(row);else $('#cardList').append(row);
  sync();markDirty();
});
sync();
form.addEventListener('input',e=>{markDirty();if(e.target.matches('[data-card-title]'))sync();});
form.addEventListener('change',e=>{markDirty();const inp=e.target;if(!inp.matches('[data-file-kind]')||!inp.files[0])return;const box=inp.closest('[data-filebox]');let preview=$('[data-file-preview]',box);if(preview){if(preview.dataset.objectUrl)URL.revokeObjectURL(preview.dataset.objectUrl);preview.remove();}preview=document.createElement(inp.dataset.fileKind==='audio'?'audio':'img');preview.dataset.filePreview='';preview.src=URL.createObjectURL(inp.files[0]);preview.dataset.objectUrl=preview.src;if(preview.tagName==='AUDIO')preview.controls=true;else{preview.className='thumb big';preview.alt='پیش‌نمایش فایل انتخابی';}box.prepend(preview);$('.file-caption',box).textContent=inp.files[0].name;});
const files=$('#gNew'),drop=$('#drop'),previews=$('#gPreviews');let urls=[];
function render(){urls.forEach(URL.revokeObjectURL);urls=[];previews.replaceChildren();[...files.files].forEach(f=>{const row=document.createElement('article');row.className='row g-row';const img=document.createElement('img');img.className='thumb gallery-thumb';img.alt=f.name;img.src=URL.createObjectURL(f);urls.push(img.src);const fields=document.createElement('div');fields.className='grow';const label=document.createElement('label');label.className='field';label.textContent='یادداشت زیر عکس';const input=document.createElement('input');input.name='g_new_cap[]';input.maxLength=120;input.placeholder=f.name;label.append(input);const label2=document.createElement('label');label2.className='field';label2.textContent='کنار کدام خاطره؟';const select=document.createElement('select');select.name='g_new_page[]';select.dataset.pageSelect='';label2.append(select);fields.append(label,label2);row.append(img,fields);previews.append(row);});sync();markDirty();}
files.addEventListener('change',render);['dragenter','dragover'].forEach(ev=>drop.addEventListener(ev,e=>{e.preventDefault();drop.classList.add('over');}));drop.addEventListener('dragleave',()=>drop.classList.remove('over'));drop.addEventListener('drop',e=>{e.preventDefault();drop.classList.remove('over');files.files=e.dataTransfer.files;render();});
$('#musicVolume').addEventListener('input',e=>$('#volumeLabel').textContent=Number(e.target.value).toLocaleString('fa-IR')+'٪');
form.addEventListener('formdata',e=>{
  const payload={};
  for(const [name,value] of e.formData.entries()){
    if(typeof value!=='string'||['csrf','action','tab'].includes(name))continue;
    (payload[name]??=[]).push(value);
  }
  for(const name of Object.keys(payload))e.formData.delete(name);
  e.formData.set('content_payload',JSON.stringify(payload));
});
form.noValidate=true;
form.addEventListener('submit',e=>{const invalid=$$('input,textarea,select',form).find(i=>!i.checkValidity());if(invalid){e.preventDefault();location.hash=invalid.closest('.panel').id.slice(6);show();invalid.reportValidity();return;}submitting=true;});
$$('form[data-confirm]').forEach(f=>f.addEventListener('submit',e=>{if(!confirm(f.dataset.confirm))e.preventDefault();}));
addEventListener('beforeunload',e=>{if(dirty&&!submitting){e.preventDefault();e.returnValue='';}});
})();
