(function(){
'use strict';
var WS_PORT = 4020, POLL_MS = 3000, WS_RETRY = 3;
var S = {ws:null, mode:'', retry:0, data:null, tab:'overview', pollTimer:null, wsFail:0, appKey:''};

function $(id){return document.getElementById(id);}
function esc(s){var d=document.createElement('div');d.textContent=(s==null?'':String(s));return d.innerHTML;}
function toast(m){var t=$('toast');t.textContent=m;t.className='on';clearTimeout(window.__tt);window.__tt=setTimeout(function(){t.className='';},2600);}

function setLink(cls,text,mode){
  $('dot').className='dot '+cls; $('linkText').textContent=text;
  if(mode){S.mode=mode;$('modeText').textContent=(mode==='ws'?'WebSocket 实时':'轮询模式（3 秒）');}
}

/* ---------- 数据获取：WS 优先，失败回退轮询 ---------- */
function wsUrl(){
  var proto = (location.protocol === 'https:') ? 'wss:' : 'ws:';
  return proto + '//' + location.hostname + ':' + WS_PORT;
}
function connectWS(){
  if(!window.WebSocket){startPoll('浏览器不支持 WebSocket');return;}
  var ws;
  try{ws=new WebSocket(wsUrl());}catch(e){startPoll('WebSocket 创建失败');return;}
  S.ws=ws;
  var closed=false;
  ws.onopen=function(){
    S.wsFail=0;S.retry=0;
    // 面板只订阅、不 login：避免把自己的连接算进「在线用户」，也不污染应用统计
    ws.send(JSON.stringify({act:'subscribe',topic:'stats'}));
    setLink('on','实时推送中','ws');
  };
  ws.onmessage=function(ev){
    var m;try{m=JSON.parse(ev.data);}catch(e){return;}
    if(m.act==='stats'){render(m);}
    else if(m.act==='ping'){try{ws.send(JSON.stringify({act:'pong'}));}catch(e){}}
    else if(m.act==='subscribed'){toast('已订阅实时数据');}
  };
  ws.onerror=function(){closed=true;};
  ws.onclose=function(){
    S.ws=null;
    if(S.mode==='poll'){return;}   // 已经降级，不重复处理
    S.retry++;
    if(S.wsFail>=WS_RETRY){startPoll('WS 连续失败');return;}
    setLink('off','重连中 '+S.retry,'ws');
    setTimeout(connectWS, Math.min(1000*S.retry,5000));
  };
}
function startPoll(why){
  if(S.mode==='poll'){return;}
  S.wsFail++;
  if(S.ws){try{S.ws.close();}catch(e){}S.ws=null;}
  S.mode='poll';setLink('poll','轮询模式','poll');
  toast('已回退轮询：'+(why||''));
  fetchStats();
  if(S.pollTimer){clearInterval(S.pollTimer);}
  S.pollTimer=setInterval(fetchStats,POLL_MS);
  // 每 60 秒再试一次 WS（网络恢复就切回实时）
  setTimeout(function(){
    if(S.mode!=='poll'){return;}
    try{var probe=new WebSocket(wsUrl());
      probe.onopen=function(){probe.close();clearInterval(S.pollTimer);S.pollTimer=null;S.mode='';S.wsFail=0;connectWS();};
      probe.onerror=function(){try{probe.close();}catch(e){}};
    }catch(e){}
  },60000);
}
function fetchStats(){
  fetch('?do=stats&view=json',{credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(function(r){return r.json();})
    .then(function(j){if(j&&j.data){render(j.data);}})
    .catch(function(){setLink('off','取数失败','poll');});
}

/* ---------- 渲染 ---------- */
function kpi(cls,val,label){return '<div class="k '+cls+'"><b>'+val+'</b><span>'+label+'</span></div>';}
function render(d){
  S.data=d;
  $('tsText').textContent='更新于 '+(d.server_time||'');
  if(!d.available){setLink('off','推送服务未启动','poll');}else if(S.mode==='ws'){setLink('on','实时推送中','ws');}
  $('kpis').innerHTML=
    kpi(d.available?'ok':'bad', d.available?'在线':'离线', '服务状态')+
    kpi('', d.online_cnt||0, '在线用户')+
    kpi('', d.client_cnt||0, '连接数')+
    kpi(d.offline_cnt>0?'warn':'', d.offline_cnt||0, '待补发目标')+
    kpi('', (d.apps||[]).length, '已注册应用')+
    kpi(d.audit&&d.audit.close>0?'warn':'', (d.audit&&d.audit.close)||0, '今日断开');

  var max=1;(d.apps||[]).forEach(function(a){if(a.online>max)max=a.online;});
  var bars='';
  (d.apps||[]).forEach(function(a){
    var pct=Math.round((a.online/max)*100);
    bars+='<div style="margin-bottom:12px"><div style="display:flex;justify-content:space-between;font-size:13px">'
      +'<span><b class="mono">'+esc(a.app)+'</b> <span style="color:#94a3b8">'+esc(a.label)+'</span></span>'
      +'<span style="color:#64748b">在线 '+a.online+' · 离线 '+a.offline+'</span></div>'
      +'<div class="bar"><i style="width:'+pct+'%"></i></div></div>';
  });
  $('appBars').innerHTML=bars||'<div class="empty">还没有应用</div>';

  // 连接
  var now=Math.floor(Date.now()/1000), rows='';
  (d.clients||[]).forEach(function(c){
    var alive=c.online_at>0?(now-c.online_at):0;
    rows+='<tr><td class="mono">'+esc(c.uid||'-')+'</td><td class="mono">'+esc(c.ip||'-')+'</td>'
      +'<td class="mono">'+(c.port||'-')+'</td><td>'+(c.online_at?new Date(c.online_at*1000).toLocaleTimeString():'-')+'</td>'
      +'<td>'+(alive?Math.floor(alive/60)+' 分':'<1 分')+'</td></tr>';
  });
  $('clientRows').innerHTML=rows||'<tr><td colspan="5" class="empty">当前没有客户端连接</td></tr>';

  // 应用
  var arows='';
  (d.apps||[]).forEach(function(a){
    arows+='<tr><td class="mono">'+(a.app_id||'-')+'</td><td class="mono">'+esc(a.app)+'</td><td>'+esc(a.label)+'</td>'
      +'<td class="mono" style="color:#94a3b8">（点「应用注册」页查看完整 key）</td>'
      +'<td>'+a.online+' / '+a.offline+'</td>'
      +'<td>'+(a.persist?'<span style="color:#059669">双写</span>':'<span style="color:#d97706">仅内存</span>')+'</td>'
      +'<td><button class="lbtn" data-rot="'+esc(a.app)+'">重置key</button>'
      +'<button class="lbtn red" data-del="'+esc(a.app)+'">删除</button></td></tr>';
  });
  $('appRows').innerHTML=arows||'<tr><td colspan="6" class="empty">还没有注册应用</td></tr>';

  // 离线
  var orows='';
  (d.offline||[]).forEach(function(o){
    orows+='<tr><td class="mono">'+esc(o.target)+'</td><td>'+new Date(o.since*1000).toLocaleString()+'</td>'
      +'<td><button class="lbtn red" data-clr="'+esc(o.target)+'">清空</button></td></tr>';
  });
  $('offRows').innerHTML=orows||'<tr><td colspan="3" class="empty">没有待补发的离线消息</td></tr>';

  // 审计
  var au=d.audit||{};
  $('auditKpis').innerHTML=
    kpi('', au.send||0, '推送次数')+kpi('ok', au.sent_ok||0, '在线送达')+
    kpi('warn', au.queued||0, '转离线')+kpi('', au.ack||0, '客户端回执')+
    kpi(au.close>0?'warn':'', au.close||0, '断开/限流');
}

/* ---------- 交互 ---------- */
function post(path,data){
  var b=new URLSearchParams();Object.keys(data).forEach(function(k){b.set(k,data[k]);});
  return fetch(path,{method:'POST',credentials:'same-origin',
    headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8','X-Requested-With':'XMLHttpRequest'},
    body:b.toString()}).then(function(r){return r.json();});
}
document.getElementById('tabs').addEventListener('click',function(e){
  var b=e.target.closest('button[data-t]');if(!b){return;}
  S.tab=b.getAttribute('data-t');
  Array.prototype.forEach.call(this.querySelectorAll('button'),function(x){x.classList.toggle('on',x===b);});
  Array.prototype.forEach.call(document.querySelectorAll('.panel'),function(p){
    p.classList.toggle('on',p.id==='p-'+S.tab);});
  if(S.tab==='apps'){loadKeys();}
  if(S.tab==='audit'){loadAudit();}
});
function loadKeys(){
  fetch('?do=apps&view=json',{credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(function(r){return r.json();}).then(function(j){
      var list=(j&&j.data&&j.data.apps)||[],map={};
      list.forEach(function(a){map[a.app]={id:a.app_id,key:a.key};});
      Array.prototype.forEach.call(document.querySelectorAll('#appRows tr'),function(tr){
        var b=tr.querySelector('button[data-rot]');if(!b){return;}
        var name=b.getAttribute('data-rot'),one=map[name];if(!one){return;}
        tr.children[0].textContent=one.id||'-';
        tr.children[3].textContent=one.key||'-';
        tr.children[3].className='mono';
      });
    }).catch(function(){});
}
$('btnReg').addEventListener('click',function(){
  var b=this;b.disabled=true;
  post('?do=register&view=json',{app:$('rApp').value,label:$('rLabel').value,ttl:$('rTtl').value,
    max:$('rMax').value,persist:($('rPersist')?$('rPersist').value:'1')})
    .then(function(j){toast(j.message||(j.ok?'已注册':'失败'));if(j.ok){setTimeout(function(){location.reload();},800);}})
    .catch(function(e){toast('失败：'+e.message);}).finally(function(){b.disabled=false;});
});
document.addEventListener('click',function(e){
  if(e.target&&e.target.id==='btnAudit'){loadAudit();}
});
$('btnSend').addEventListener('click',function(){
  var b=this;b.disabled=true;
  post('?do=send&view=json',{app:$('sApp').value||'default',target:$('sTarget').value||'all',
    title:$('sTitle').value,msg:$('sMsg').value})
    .then(function(j){toast((j.ok?'已发送：':'未直投：')+(j.message||''));if(S.mode==='ws'||S.mode==='poll'){fetchStats();}})
    .catch(function(e){toast('失败：'+e.message);}).finally(function(){b.disabled=false;});
});
document.addEventListener('click',function(e){
  var b=e.target.closest('button[data-rot],button[data-del],button[data-clr]');
  if(!b||b.disabled){return;}
  var rot=b.getAttribute('data-rot'),del=b.getAttribute('data-del'),clr=b.getAttribute('data-clr');
  if(rot){if(!confirm('重置 '+rot+' 的 key？旧 key 立即失效。')){return;}
    b.disabled=true;post('?do=rotate&view=json',{app:rot}).then(function(j){toast(j.message||'已重置');loadKeys();});return;}
  if(del){if(!confirm('删除应用 '+del+'？')){return;}
    b.disabled=true;post('?do=remove&view=json',{app:del}).then(function(j){toast(j.message||'已删除');fetchStats();});return;}
  if(clr){var p=clr.split(':');if(p.length<2){return;}
    b.disabled=true;post('?do=clear&view=json',{app:p[0],uid:p.slice(1).join(':')})
      .then(function(j){toast(j.message||'已清空');fetchStats();});return;}
});


/* ---------- 审计明细 ---------- */
function auditTagName(t){
  return {SEND:'推送',ACK:'回执',CLOSE:'断开',MISS:'缺应用'}[t]||t;
}
function loadAudit(){
  var day=$('aDay').value||'', tag=$('aTag').value||'', app=$('aApp').value||'';
  var qs='?do=logs&view=json&lines=200&day='+encodeURIComponent(day)
    +'&tag='+encodeURIComponent(tag)+'&app='+encodeURIComponent(app);
  fetch(qs,{credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(function(r){return r.json();})
    .then(function(j){
      var d=(j&&j.data)||{}, rows=d.rows||[], days=d.days||[];
      if($('aDay').options.length===0){
        var opt='';
        days.forEach(function(x){opt+='<option value="'+esc(x)+'">'+esc(x)+'</option>';});
        if(!opt){opt='<option value="'+esc(d.day||'')+'">'+esc(d.day||'今天')+'</option>';}
        $('aDay').innerHTML=opt;
        if(d.day){$('aDay').value=d.day;}
      }
      var h='';
      rows.forEach(function(r){
        var res='—';
        if(r.tag==='SEND'){res=r.ok?'<span style="color:#059669">送达 '+r.sent+'</span>':'<span style="color:#d97706">转离线 '+r.queued+'</span>';}
        else if(r.tag==='ACK'){res='<span style="color:#059669">已回执</span>';}
        else if(r.tag==='CLOSE'){res='<span style="color:#dc2626">断开</span>';}
        else if(r.tag==='MISS'){res='<span style="color:#dc2626">未知应用</span>';}
        var detail=[];
        if(r.msg_id)detail.push('msg='+r.msg_id.slice(0,12));
        if(r.bytes)detail.push(r.bytes+'B');
        if(r.via)detail.push('via='+r.via);
        if(r.reason)detail.push('reason='+r.reason);
        if(r.client)detail.push('client='+r.client);
        if(r.note)detail.push(r.note);
        h+='<tr><td class="mono">'+esc(r.clock)+'</td><td>'+esc(auditTagName(r.tag))+'</td>'
          +'<td class="mono">'+esc(r.app||'-')+'</td><td class="mono">'+esc(r.target||'-')+'</td>'
          +'<td>'+res+'</td><td style="color:#64748b;font-size:12px">'+esc(detail.join(' · '))+'</td></tr>';
      });
      $('auditRows').innerHTML=h||'<tr><td colspan="6" class="empty">这一天没有记录</td></tr>';
      $('auditTip').textContent='共 '+rows.length+' 条（最多显示 200 条，新的在前）';
    })
    .catch(function(e){$('auditTip').textContent='读取失败：'+e.message;});
}

/* ---------- 启动 ---------- */
fetchStats();          // 先拿一次，保证首屏不空
connectWS();           // 再尝试实时
document.addEventListener('visibilitychange',function(){
  if(document.visibilityState==='visible'){
    if(!S.ws&&S.mode!=='poll'){S.wsFail=0;S.retry=0;connectWS();}
    else if(S.mode==='poll'){fetchStats();}
  }
});
window.addEventListener('beforeunload',function(){if(S.ws){try{S.ws.close();}catch(e){}}});
})();
