(function(){
  if(document.getElementById('eduChatRoot')) return;
  const api=url=> {
    const base=document.querySelector('link[href*="assets/css/app.css"]')?.href || location.origin+'/';
    try{ return (window.APP_BASE||'') + url } catch(e){ return url }
  };
  const endpoint = (()=>{
    const s=document.currentScript?.src || '';
    // derive base from page url
    const m=location.pathname.match(/^(.*?\/public_html)/);
    return (m?m[1]:'') + '/api/chatbot.php';
  })();

  const root=document.createElement('div');
  root.id='eduChatRoot';
  root.innerHTML=`
  <button id="eduChatBtn" aria-label="Open chat" style="position:fixed;right:18px;bottom:18px;z-index:9999;width:56px;height:56px;border-radius:50%;background:#0F172A;color:#fff;border:0;box-shadow:0 8px24px rgba(0,0,0,.18);display:flex;align-items:center;justify-content:center;font-size:22px;cursor:pointer">💬</button>
  <div id="eduChatPanel" style="position:fixed;right:18px;bottom:80px;z-index:9999;width:360px;max-width:92vw;height:480px;max-height:70vh;background:#fff;border:1px solid #e2e8f0;border-radius:16px;box-shadow:0 16px 40px rgba(0,0,0,.18);display:none;flex-direction:column;overflow:hidden">
    <div style="background:#0F172A;color:#fff;padding:12px 14px;display:flex;align-items:center;justify-content:space-between">
      <div><div style="font-weight:700;font-size:14px">EduPortal AI</div><div style="font-size:11px;opacity:.8">Datamex College • Gemini ${end()}</div></div>
      <button id="eduChatClose" style="background:rgba(255,255,255,.15);border:0;color:#fff;width:28px;height:28px;border-radius:8px;cursor:pointer">×</button>
    </div>
    <div id="eduChatMsgs" style="flex:1;overflow-y:auto;padding:12px;display:flex;flex-direction:column;gap:8px;background:#F8FAFC">
      <div style="background:#fff;border:1px solid #e2e8f0;padding:10px 12px;border-radius:12px;font-size:13px;color:#334155">Hi! I'm your EduPortal assistant. Ask me about enrollment, PayRex, permits, subjects, or verification. How can I help?</div>
    </div>
    <form id="eduChatForm" style="display:flex;gap:8px;padding:10px;border-top:1px solid #e2e8f0;background:#fff">
      <input id="eduChatInput" placeholder="Ask anything..." maxlength="800" style="flex:1;border:1px solid #cbd5e1;border-radius:10px;padding:10px 12px;font-size:13px;outline:none">
      <button type="submit" style="background:#0F172A;color:#fff;border:0;border-radius:10px;padding:0 14px;font-weight:600;cursor:pointer">Send</button>
    </form>
    <div style="font-size:10px;color:#94a3b8;text-align:center;padding:4px 8px">Powered by Gemini • Your key stays on server</div>
  </div>`;
  function end(){ try{return document.body.dataset.app||''}catch(e){return ''}}
  document.body.appendChild(root);
  const btn=document.getElementById('eduChatBtn'), panel=document.getElementById('eduChatPanel'), close=document.getElementById('eduChatClose');
  const msgs=document.getElementById('eduChatMsgs'), form=document.getElementById('eduChatForm'), input=document.getElementById('eduChatInput');
  let isGuest=false;
  let history=[];
  async function loadHistory(){
    try{
      const r=await fetch(endpoint+'?action=history',{credentials:'same-origin'});
      const j=await r.json();
      if(j.guest){
        // guest: show welcome, keep chat enabled, history not saved
        const note=document.createElement('div');
        note.style.cssText='background:#f0f9ff;border:1px solid #bae6fd;padding:8px 10px;border-radius:10px;font-size:11px;color:#0c4a6e;text-align:center';
        note.innerHTML='Chatting as <strong>guest</strong> — <a href="'+(location.origin + (location.pathname.match(/^(.*?\/public_html)/)?.[1]||'')+'/login')+'" style="color:#2563eb;font-weight:600">sign in</a> to save your chat history.';
        msgs.appendChild(note);
        return;
      }
      if(j.history && j.history.length){
        msgs.innerHTML='';
        j.history.forEach(row=>{
          const who=row.sender==='user'?'user':'bot';
          addMsg(row.message, who);
          history.push({role: row.sender==='user'?'user':'model', text: row.message});
        });
        if(history.length>12) history=history.slice(-12);
      }
    }catch(e){}
  }
  loadHistory();
  btn.addEventListener('click',()=>{ panel.style.display=panel.style.display==='none'?'flex':'none'; if(panel.style.display==='flex') input.focus(); if(panel.style.display==='flex' && msgs.children.length<=1) loadHistory(); });
  close.addEventListener('click',()=> panel.style.display='none');
  function clean(text){
    return (text||'').replace(/\*\*(.*?)\*\*/g,'$1').replace(/\*(.*?)\*/g,'$1').replace(/`(.*?)`/g,'$1').replace(/^#+\s*/gm,'').replace(/^\s*[-•]\s*/gm,'- ');
  }
  function addMsg(text, who){
    const d=document.createElement('div');
    d.style.cssText= who==='user' ? 'align-self:flex-end;background:#0F172A;color:#fff;padding:8px 10px;border-radius:12px;max-width:82%;font-size:13px;white-space:pre-wrap;line-height:1.5' : 'background:#fff;border:1px solid #e2e8f0;padding:10px 12px;border-radius:12px;font-size:13px;color:#1e293b;white-space:pre-wrap;line-height:1.5;max-width:85%';
    d.textContent=who==='bot' ? clean(text) : text;
    msgs.appendChild(d); msgs.scrollTop=msgs.scrollHeight;
  }
  form.addEventListener('submit', async (e)=>{
    e.preventDefault();
    const text=input.value.trim(); if(!text) return;
    addMsg(text,'user'); history.push({role:'user',text}); input.value='';
    const thinking=document.createElement('div'); thinking.textContent='…'; thinking.style.cssText='font-size:12px;color:#94a3b8;padding:4px'; msgs.appendChild(thinking); msgs.scrollTop=msgs.scrollHeight;
    try{
      const csrf=(document.querySelector('meta[name="csrf-token"]')||{}).content||'';
      const r=await fetch(endpoint,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({message:text,history})});
      const j=await r.json();
      thinking.remove();
      if(j.error){ addMsg(j.error,'bot'); return; }
      const reply=j.reply || 'No response';
      addMsg(reply,'bot'); history.push({role:'model',text:reply}); if(history.length>12) history=history.slice(-12);
    }catch(err){ thinking.remove(); addMsg('Network error, please try again.','bot'); }
  });
})();
