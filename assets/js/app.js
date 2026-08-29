(()=>{
  const $=(s,c=document)=>c.querySelector(s), $$=(s,c=document)=>[...c.querySelectorAll(s)];
  const navBtn=$('[data-nav-toggle]'), nav=$('[data-main-nav]');
  navBtn?.addEventListener('click',()=>nav?.classList.toggle('open'));
  $$('[data-toast]').forEach(t=>{const close=$('button',t);close?.addEventListener('click',()=>t.remove());setTimeout(()=>t.remove(),6500)});
  const header=$('.site-header');
  const syncHeader=()=>header?.classList.toggle('is-scrolled',window.scrollY>18);
  window.addEventListener('scroll',syncHeader,{passive:true}); syncHeader();
  const consent=$('[data-consent]');
  const ga4Id=document.body.dataset.ga4Id||'';
  const internalAnalytics=document.body.dataset.internalAnalytics==='1';
  const consentKey='khatauat_analytics_consent';
  const getConsent=()=>localStorage.getItem(consentKey);
  let pageviewSent=false;
  const loadGA4=()=>{
    if(!ga4Id || window.__khatauatGaLoaded) return;
    window.__khatauatGaLoaded=true;
    const s=document.createElement('script');s.async=true;s.src='https://www.googletagmanager.com/gtag/js?id='+encodeURIComponent(ga4Id);document.head.appendChild(s);
    window.dataLayer=window.dataLayer||[];window.gtag=function(){dataLayer.push(arguments)};gtag('js',new Date());gtag('config',ga4Id,{anonymize_ip:true});
  };
  const sendInternalPageview=()=>{
    if(!internalAnalytics || pageviewSent || getConsent()!=='granted') return;
    pageviewSent=true;
    const q=new URLSearchParams(location.search);
    const payload=JSON.stringify({path:location.pathname,referrer:document.referrer||'',utm_source:q.get('utm_source')||''});
    if(navigator.sendBeacon){try{navigator.sendBeacon('/analytics/event',new Blob([payload],{type:'application/json'}));return}catch(e){}}
    fetch('/analytics/event',{method:'POST',headers:{'Content-Type':'application/json'},body:payload,keepalive:true}).catch(()=>{});
  };
  const startMeasurement=()=>{loadGA4();sendInternalPageview()};
  if(ga4Id||internalAnalytics){const c=getConsent();if(c==='granted')startMeasurement();else if(!c&&consent)consent.hidden=false;}
  $('[data-consent-allow]')?.addEventListener('click',()=>{localStorage.setItem(consentKey,'granted');if(consent)consent.hidden=true;startMeasurement()});
  $('[data-consent-deny]')?.addEventListener('click',()=>{localStorage.setItem(consentKey,'denied');if(consent)consent.hidden=true});
  const track=(name,params={})=>{if(getConsent()==='granted'&&typeof window.gtag==='function')gtag('event',name,params)};
  $$('[data-track-event]').forEach(el=>el.addEventListener('click',()=>track(el.dataset.trackEvent,{service_id:el.dataset.serviceId||undefined})));
  $$('[data-track="official-outbound"]').forEach(el=>el.addEventListener('click',()=>track('official_outbound_click',{service_id:el.dataset.serviceId||undefined,service_name:el.dataset.serviceName||undefined,destination:new URL(el.href,location.href).hostname})));
  $('[data-smart-path-form]')?.addEventListener('submit',()=>track('smart_path_complete'));
  $$('[data-ad-experiment]').forEach(slot=>slot.addEventListener('click',e=>{
    if(!e.target.closest('a,button,[role="button"]')) return;
    const experiment_id=Number(slot.dataset.adExperiment||0),variant=slot.dataset.adVariant||'';
    if(!experiment_id||!variant) return;
    fetch('/ad/event',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({experiment_id,variant}),keepalive:true}).catch(()=>{});
  }));
})();
(()=>{
  document.querySelectorAll('.v143-service-card,.v143-article-card,.v143-route-card,.v143-change-card,.v143-panel').forEach((el,i)=>{el.style.setProperty('--v143-delay',`${Math.min(i%6,5)*35}ms`)});
  const current=location.pathname.replace(/\/$/,'')||'/';
  document.querySelectorAll('.main-nav a').forEach(a=>{try{const p=new URL(a.href,location.origin).pathname.replace(/\/$/,'')||'/';if(p===current||(p!=='/'&&current.startsWith(p)))a.classList.add('is-current')}catch(e){}});
})();
(()=>{
  const input=document.querySelector('#journey-search');
  document.querySelectorAll('[data-search-fill]').forEach(btn=>btn.addEventListener('click',()=>{
    if(!input)return; input.value=btn.dataset.searchFill||btn.textContent.trim(); input.focus();
  }));
  if(!window.matchMedia('(prefers-reduced-motion: reduce)').matches && 'IntersectionObserver' in window){
    const io=new IntersectionObserver(entries=>entries.forEach(e=>{if(e.isIntersecting){e.target.classList.add('is-visible');io.unobserve(e.target)}}),{threshold:.12});
    document.querySelectorAll('.journey-section-head,.sector-node,.journey-map-card,.trust-console,.utility-card').forEach(el=>io.observe(el));
  }
})();
(()=>{
  const open=document.querySelector('[data-owner-sidebar-open]');
  const close=document.querySelector('[data-owner-sidebar-close]');
  const sidebar=document.querySelector('[data-owner-sidebar]');
  const set=v=>document.documentElement.classList.toggle('is-owner-sidebar-open',v);
  open?.addEventListener('click',()=>set(true));
  close?.addEventListener('click',()=>set(false));
  sidebar?.querySelectorAll('a').forEach(a=>a.addEventListener('click',()=>set(false)));
  document.addEventListener('keydown',e=>{if(e.key==='Escape')set(false)});
})();
(()=>{
  const forms=[...document.querySelectorAll('[data-source-approve-form]')];
  if(!forms.length) return;
  const notify=(message,type='success')=>{
    const t=document.createElement('div');
    t.className=`toast toast-${type}`;
    t.setAttribute('data-toast','');
    t.innerHTML='';
    const text=document.createElement('span'); text.textContent=message;
    const close=document.createElement('button'); close.type='button'; close.setAttribute('aria-label','إغلاق'); close.textContent='×';
    close.addEventListener('click',()=>t.remove());
    t.append(text,close); document.body.appendChild(t); setTimeout(()=>t.remove(),5200);
  };
  forms.forEach(form=>form.addEventListener('submit',async e=>{
    if(!form.reportValidity()) return;
    if(!window.fetch) return; // normal POST fallback preserves row anchor server-side
    e.preventDefault();
    const row=form.closest('[data-source-row]');
    const button=form.querySelector('[data-source-approve-button]');
    const feedback=form.querySelector('[data-source-approve-feedback]');
    const original=button?.textContent||'اعتماد';
    if(button){button.disabled=true;button.textContent='جارٍ الاعتماد…'}
    if(feedback){feedback.hidden=true;feedback.textContent=''}
    try{
      const res=await fetch(form.action,{method:'POST',body:new FormData(form),headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'},credentials:'same-origin'});
      const data=await res.json().catch(()=>null);
      if(!res.ok||!data?.ok) throw new Error(data?.message||'تعذر اعتماد المصدر.');
      const status=row?.querySelector('[data-source-status]');
      if(status){status.textContent='active';status.classList.remove('badge-warn');status.classList.add('badge-success')}
      const action=row?.querySelector('[data-source-action]');
      if(action){
        action.innerHTML='';
        const a=document.createElement('a'); a.className='btn btn-soft btn-sm'; a.target='_blank'; a.rel='noopener'; a.href=data.url||'#'; a.textContent='فتح ↗'; action.appendChild(a);
      }
      const active=document.querySelector('[data-source-stat-active]');
      const candidates=document.querySelector('[data-source-stat-candidates]');
      if(active&&data.stats) active.textContent=Number(data.stats.active||0).toLocaleString('ar-SA');
      if(candidates&&data.stats) candidates.textContent=Number(data.stats.candidates||0).toLocaleString('ar-SA');
      row?.classList.add('is-source-approved');
      setTimeout(()=>row?.classList.remove('is-source-approved'),1200);
      notify(data.message||'تم اعتماد المصدر.');
    }catch(err){
      if(button){button.disabled=false;button.textContent=original}
      if(feedback){feedback.hidden=false;feedback.textContent=err?.message||'تعذر اعتماد المصدر.'}
      notify(err?.message||'تعذر اعتماد المصدر.','error');
    }
  }));
})();
(()=>{
  const group=document.querySelector('[data-owner-settings-group]');
  const toggle=document.querySelector('[data-owner-settings-toggle]');
  if(group&&toggle){
    toggle.addEventListener('click',()=>{
      const open=group.classList.toggle('is-open');
      toggle.setAttribute('aria-expanded',open?'true':'false');
    });
  }
  document.querySelectorAll('form[data-preserve-scroll]').forEach(form=>{
    form.addEventListener('submit',()=>sessionStorage.setItem('kh_owner_scroll',String(window.scrollY||0)));
  });
  const saved=sessionStorage.getItem('kh_owner_scroll');
  if(saved!==null){ sessionStorage.removeItem('kh_owner_scroll'); requestAnimationFrame(()=>window.scrollTo(0,Number(saved)||0)); }
})();
(()=>{
  const reduce=window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
  const intro=document.querySelector('[data-kh-intro]');
  if(intro){
    const key='khatauat_intro_seen_v216';
    const seen=sessionStorage.getItem(key)==='1';
    if(reduce||seen){intro.remove();}
    else{
      sessionStorage.setItem(key,'1');
      window.setTimeout(()=>intro.classList.add('is-done'),1050);
      window.setTimeout(()=>intro.remove(),1550);
    }
  }
  const reveal=[...document.querySelectorAll('[data-reveal]')];
  if(!reveal.length) return;
  if(reduce||!('IntersectionObserver' in window)){reveal.forEach(el=>el.classList.add('is-visible'));return;}
  reveal.forEach((el,i)=>el.style.transitionDelay=`${Math.min(i%5,4)*55}ms`);
  const io=new IntersectionObserver(entries=>entries.forEach(entry=>{
    if(entry.isIntersecting){entry.target.classList.add('is-visible');io.unobserve(entry.target);}
  }),{threshold:.09,rootMargin:'0px 0px -4% 0px'});
  reveal.forEach(el=>io.observe(el));
})();