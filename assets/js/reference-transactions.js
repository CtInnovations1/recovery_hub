(function(){
 const form=document.getElementById('commerce')||document.getElementById('exit');if(!form)return;
 const isCommerce=form.id==='commerce',button=document.getElementById('submit'),message=document.getElementById('message');
 const modeControl=document.getElementById(isCommerce?'action':'operation');modeControl.addEventListener('change',()=>{if(isCommerce?modeControl.value!=='export':modeControl.value!=='sales-handoff'){document.getElementById('serial').value='';}});
 form.onsubmit=async event=>{
  event.preventDefault();const operation=document.getElementById(isCommerce?'action':'operation').value;
  const chosen=window.recoveryFlowSelected||[],batch=(isCommerce?operation==='export':operation==='sales-handoff')&&chosen.length>0;
  const serials=batch?chosen.map(r=>r.serial_number):[document.getElementById('serial').value];
  if(!batch&&chosen.length>1){message.textContent='Clear the selection or select one device for payment simulation or physical scan-out. Every physical exit requires its own serial scan.';return;}
  if(!serials.length || serials.some(s=>!s.trim())){message.textContent='Select or scan a serialized device.';return;}
  button.disabled=true;const results=[];let confirmed=0;
  try{
   for(const serial of serials){
    let payload,url;
    if(isCommerce){url='/api/commerce/execute.php';payload={action:operation,serial_number:serial,price:document.getElementById('price').value,order_id:document.getElementById('order').value,location_scan:document.getElementById('location').value,direct_costs:document.getElementById('costs').value};}
    else{url='/api/disposition/execute.php';payload={operation,currency:'USD',direct_costs:document.getElementById('costs').value,serial_number:serial,sales_order_id:document.getElementById('order').value,location_scan:document.getElementById('location').value,final_disposition:document.getElementById('disposition').value};}
    const response=await fetch(url,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(payload)}),data=await response.json();
    if(!response.ok||!data.success)throw new Error(serial+': '+(data.message||'Transaction rejected.'));
    confirmed++;const text=isCommerce?data.message+(data.order_id?'\n'+data.order_id:''):data.serial_number+' · '+data.status+' · ERP receipt '+data.request_key;
    results.push(text);if(data.order_id)document.getElementById('order').value=data.order_id;
    message.textContent=(batch?confirmed+' / '+serials.length+' confirmed.\n':'')+results.join('\n');
   }
  }catch(error){message.textContent=(confirmed?confirmed+' device(s) already confirmed. Retry the same request to recover receipts safely.\n':'')+results.join('\n')+'\n'+error.message;}
  finally{button.disabled=false;}
 };
 const preview=document.getElementById('listingPreviewTitle');
 if(preview){const update=()=>{const rows=window.recoveryFlowSelected||[];preview.textContent=rows.length===1?rows[0].product_description||rows[0].product_id||'Serialized Recovery device':rows.length?rows.length+' serialized listings':'Select a Recovery device';document.getElementById('listingPreviewPrice').textContent=document.getElementById('price').value?new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'}).format(Number(document.getElementById('price').value)):'—';document.getElementById('listingPreviewUnits').textContent=rows.length||1;};document.getElementById('price').addEventListener('input',update);document.getElementById('flowRows').addEventListener('change',update);document.getElementById('flowAll').addEventListener('click',update);document.getElementById('flowClear').addEventListener('click',update);update();}
 if(new URLSearchParams(location.search).get('operation')==='harvest'&&!isCommerce){document.getElementById('operation').value='harvest';document.getElementById('operation').dispatchEvent(new Event('change'));}
})();
