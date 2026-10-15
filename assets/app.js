(() => {
  'use strict';
  const $ = (s, r=document) => r.querySelector(s), $$ = (s,r=document) => [...r.querySelectorAll(s)];
  const cfg=JSON.parse($('#bookConfig').textContent), spreads=$$('.spread'), music=$('#bgMusic');
  let current=0, heartShown=false, busy=false, sent=false, replyTimer;
  const cover=$('#closedBook'), interior=$('#bookInterior'), openButton=$('#openBook');
  let opened=!cover, opening=false;
  if(interior)interior.inert=true;
  function openBook(){
    if(opened||opening)return;
    opening=true;
    cover.classList.add('is-opening');
    openButton.setAttribute('aria-disabled','true');
    const reveal=()=>{
      opened=true;opening=false;
      openButton.setAttribute('aria-expanded','true');
      cover.hidden=true;interior.hidden=false;interior.inert=false;
      interior.classList.add('book-revealed');
      const heading=$('h1,h2',spreads[0]);
      heading.tabIndex=-1;heading.focus({preventScroll:true});
      $('#main').scrollIntoView({behavior:'instant',block:'start'});
    };
    if(matchMedia('(prefers-reduced-motion: reduce)').matches)reveal();
    else setTimeout(reveal,850);
  }
  openButton?.addEventListener('click',openBook);
  const fa=n=>n.toLocaleString('fa-IR');
  const close=dialog=>dialog.close();
  $$('[data-close]').forEach(b=>b.addEventListener('click',()=>close(b.closest('dialog'))));
  $$('dialog').forEach(d=>d.addEventListener('click',e=>{if(e.target===d){const r=d.getBoundingClientRect();if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)close(d);}}));
  function show(i, focus=false) {
    if(!opened||i<0||i>=spreads.length||i===current)return;
    $$('audio.voice-audio').forEach(a=>a.pause());
    spreads[current].hidden=true;spreads[current].inert=true;
    current=i;const page=spreads[i];page.hidden=false;page.inert=false;
    $('#prevPage').disabled=i===0;$('#nextPage').disabled=i===spreads.length-1;
    $('#nextPage span').textContent=i===spreads.length-2?cfg.finalNext:'ورق بزن';
    $$('[data-goto]').forEach(b=>{if(+b.dataset.goto===i)b.setAttribute('aria-current','step');else b.removeAttribute('aria-current');});
    $('#pageIndicator').textContent=`${fa(i+1)} از ${fa(spreads.length)} · ${page.dataset.name}`;
    if(focus){const heading=$('h1,h2',page);heading.tabIndex=-1;heading.focus({preventScroll:true});}
    if(innerWidth<=600)$('#book').scrollIntoView({behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'instant':'smooth',block:'start'});
    if(i===spreads.length-1&&!heartShown&&!new URLSearchParams(location.search).has('nopop')){
      heartShown=true;const d=$('#heartDialog'), seconds=Number(d.dataset.seconds)||3;
      d.style.setProperty('--seconds',seconds+'s');d.showModal();setTimeout(()=>d.open&&d.close(),seconds*1000);
    }
  }
  $('#prevPage').onclick=()=>show(current-1,true);$('#nextPage').onclick=()=>show(current+1,true);
  $$('[data-next]').forEach(b=>b.onclick=()=>show(current+1,true));
  $$('[data-goto]').forEach(b=>b.onclick=()=>show(+b.dataset.goto,true));
  document.addEventListener('keydown',e=>{if(!opened||$('dialog[open]')||e.target.closest('input,textarea,select,audio,button'))return;if(e.key==='ArrowLeft'){e.preventDefault();show(current+1,true);}if(e.key==='ArrowRight'){e.preventDefault();show(current-1,true);}});
  let touch;
  $('#book').addEventListener('touchstart',e=>{touch=e.target.closest('button,input,textarea,audio')?null:{x:e.touches[0].clientX,y:e.touches[0].clientY};},{passive:true});
  $('#book').addEventListener('touchend',e=>{if(!touch)return;const dx=e.changedTouches[0].clientX-touch.x,dy=e.changedTouches[0].clientY-touch.y;if(Math.abs(dx)>65&&Math.abs(dx)>Math.abs(dy)*1.6)show(current+(dx>0?1:-1));touch=null;},{passive:true});
  $$('.photo-open').forEach(b=>b.onclick=()=>{const d=$('#lightbox');$('img',d).src=b.dataset.photo;$('img',d).alt=b.dataset.caption;$('figcaption',d).textContent=b.dataset.caption;d.showModal();});
  if(music){
    music.volume=Math.max(0,Math.min(1,Number(music.dataset.volume)||.35));
    const paint=()=>{$('#musicBtn').setAttribute('aria-pressed',String(!music.paused));$('#musicBtn').setAttribute('aria-label',music.paused?'پخش موسیقی':'توقف موسیقی');};
    $('#musicBtn').onclick=async()=>{if(!music.paused)music.pause();else{try{await music.play();}catch{$('#musicLabel').textContent='برای پخش، دوباره بزن';}}};
    music.addEventListener('play',paint);music.addEventListener('pause',paint);
  }
  $$('.voice-audio').forEach(a=>a.addEventListener('play',()=>{music?.pause();$$('.voice-audio').filter(other=>other!==a).forEach(other=>other.pause());}));
  const welcome=$('#welcomeDialog');
  if(welcome){
    const seenKey='comeback:welcome:v1:'+location.pathname.replace(/index\.php$/, '');
    let seen=false;
    try{seen=localStorage.getItem(seenKey)==='seen';}catch{}
    welcome.addEventListener('close',()=>{try{localStorage.setItem(seenKey,'seen');}catch{}});
    $('#startBook').addEventListener('click',()=>{
      // Call play directly inside the tap gesture, before any asynchronous work.
      if(music)music.play().catch(()=>{$('#musicLabel').textContent='برای پخش آهنگ، اینجا بزن';});
      welcome.close();
    });
    if(!seen)welcome.showModal();
  }
  const status=$('#responseStatus'), form=$('#yesForm'), choices=$('#choices');
  $('#yesBtn').onclick=()=>{form.hidden=false;choices.hidden=true;$('input[name=when]',form).focus();};
  $('#cancelForm').onclick=()=>{form.hidden=true;choices.hidden=false;$('#yesBtn').focus();};
  async function submit(type, values={}){
    if(busy||sent)return;busy=true;status.textContent='در حال رساندن پیامت…';
    $$('#choices button,#yesForm button').forEach(b=>b.disabled=true);
    try{
      const response=await fetch('respond.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({...values,type,csrf:cfg.csrf,submissionId:cfg.submissionId})});
      const data=await response.json();if(!response.ok||!data.ok)throw new Error(data.error||'پیامت ذخیره نشد. دوباره تلاش کن.');
      sent=true;status.textContent='پیامت ثبت شد. ممنون که گفتی.';form.hidden=true;choices.hidden=true;
      clearTimeout(replyTimer);replyTimer=setTimeout(()=>{$('#replyText').textContent=data.reply;$('#replyDialog').showModal();},2000);
    }catch(e){status.textContent=e.message||'اتصال قطع شد؛ دوباره تلاش کن.';}
    finally{busy=false;if(!sent)$$('#choices button,#yesForm button').forEach(b=>b.disabled=false);}
  }
  $('#noBtn').onclick=()=>submit('no');
  form.onsubmit=e=>{e.preventDefault();if(form.reportValidity())submit('yes',Object.fromEntries(new FormData(form)));};
})();
