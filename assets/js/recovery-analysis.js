(function (global) {
    'use strict';
    const fields = {product_category:'Category',product_id:'Product ID',defect_codes:'Defect',recovery_type:'Recovery Path',grade:'Grade',source_type:'Source',vendor_name:'Vendor',location_name:'Location'};
    const numeric = value => value === null || value === undefined || value === '' ? null : (Number.isFinite(Number(value)) ? Number(value) : null);
    const unique = rows => [...new Map(rows.map(row => [String(row.id),row])).values()];
    const done = row => !!row.outcome_at || row.recovery_status === 'COMPLETED';
    const days = row => {
        if (!row.entered_recovery_at) return null;
        const start=Date.parse(row.entered_recovery_at.replace(' ','T')+'Z');
        const end=row.outcome_at ? Date.parse(row.outcome_at.replace(' ','T')+'Z') : Date.now();
        return Number.isFinite(start)&&Number.isFinite(end) ? Math.max(0,(end-start)/86400000) : null;
    };
    function metrics(input) {
        const rows=unique(input);const costs=rows.map(r=>numeric(r.cost_basis)).filter(v=>v!==null);
        const estimated=rows.map(r=>numeric(r.estimated_recovery_value)).filter(v=>v!==null);
        const actualRows=rows.filter(r=>numeric(r.actual_recovery_value)!==null);
        const actual=actualRows.reduce((sum,r)=>sum+numeric(r.actual_recovery_value),0);
        const comparable=actualRows.filter(r=>numeric(r.cost_basis)!==null);
        const comparableCost=comparable.reduce((sum,r)=>sum+numeric(r.cost_basis),0);
        const comparableValue=comparable.reduce((sum,r)=>sum+numeric(r.actual_recovery_value),0);
        const ages=rows.map(days).filter(v=>v!==null);
        return {quantity:rows.length,cost:costs.length?costs.reduce((a,b)=>a+b,0):null,missingCost:rows.length-costs.length,
            estimated:estimated.length?estimated.reduce((a,b)=>a+b,0):null,actual:actualRows.length?actual:null,
            average:actualRows.length?actual/actualRows.length:null,recoveryPercent:comparableCost>0?comparableValue/comparableCost*100:null,
            averageDays:ages.length?ages.reduce((a,b)=>a+b,0)/ages.length:null,missingAge:rows.length-ages.length,
            completed:rows.filter(done).length,open:rows.filter(r=>!done(r)).length};
    }
    function grouped(rows,field) {
        const map=new Map();for(const row of unique(rows)){const values=field==='defect_codes' ? (row.defects.length?row.defects:['No defect recorded']) : [row[field]||'Unknown'];for(const value of new Set(values)){if(!map.has(value))map.set(value,[]);map.get(value).push(row);}}
        return [...map.entries()].sort((a,b)=>String(a[0]).localeCompare(String(b[0])));
    }
    function mount(input) {
        const rows=input.map(row=>({...row,defects:(row.defect_codes||'').split('||').filter(Boolean)}));
        let dimensions=['product_id','defect_codes','recovery_type'];let selected=new Set();let filtered=rows;
        const currency=v=>v===null?'—':new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'}).format(v);
        const number=v=>v===null?'—':Number(v).toFixed(1);
        const el=(tag,text,cls)=>{const n=document.createElement(tag);if(text!==undefined)n.textContent=text;if(cls)n.className=cls;return n;};
        const filters=document.getElementById('filters');const controls={};
        for(const [field,label] of Object.entries({...fields,recovery_status:'Status'})){
            const l=el('label',label);const select=el('select');select.add(new Option('All',''));
            const values=field==='defect_codes'?rows.flatMap(r=>r.defects):rows.map(r=>r[field]);
            for(const value of [...new Set(values.filter(v=>v!==null&&v!==''))].sort())select.add(new Option(value,value));
            select.onchange=render;controls[field]=select;l.append(select);filters.append(l);
        }
        for(const [key,label,type] of [['search','Serial / description','search'],['entered_from','Entered from','date'],['entered_to','Entered through','date'],['outcome_from','Outcome from','date'],['outcome_to','Outcome through','date'],['age_min','Minimum days','number'],['age_max','Maximum days','number']]){
            const l=el('label',label);const field=el('input');field.type=type;if(type==='number')field.min='0';field.oninput=render;controls[key]=field;l.append(field);filters.append(l);
        }
        function eligible(row){return !!row.erp_device_id&&!done(row)&&Number(row.pending_reroute)!==1&&Number(row.erp_available)!==0;}
        function updateSelection(){document.getElementById('selectedCount').textContent=selected.size+' selected';document.querySelectorAll('[data-device-id]').forEach(cb=>cb.checked=selected.has(cb.dataset.deviceId));}
        function serialTable(items){const wrap=el('div',undefined,'scroll');const table=el('table');const head=el('tr');for(const label of ['Select','Serial','Product','Grade','Cost basis','Gross','Direct costs','Net','Transaction','Location','Status','Days','Source','Source order','Last movement'])head.append(el('th',label));table.append(head);
            for(const row of unique(items)){const tr=el('tr');const td=el('td');const cb=el('input');cb.type='checkbox';cb.dataset.deviceId=String(row.id);cb.disabled=!eligible(row);cb.checked=selected.has(String(row.id));cb.setAttribute('aria-label','Select '+row.serial_number);cb.onchange=()=>{cb.checked?selected.add(String(row.id)):selected.delete(String(row.id));updateSelection();};td.append(cb);tr.append(td);for(const value of [row.serial_number,row.product_id,row.grade,currency(numeric(row.cost_basis)),currency(numeric(row.gross_recovery)),currency(numeric(row.direct_costs)),currency(numeric(row.actual_recovery_value)),row.external_reference_id,row.location_name,row.recovery_status,number(days(row)),row.source_type,row.source_reference,row.last_movement])tr.append(el('td',value||'—'));table.append(tr);}wrap.append(table);return wrap;}
        function branch(items,level){if(level>=dimensions.length)return serialTable(items);const wrap=el('div');for(const [name,group] of grouped(items,dimensions[level])){const d=el('details');const sum=el('summary');const m=metrics(group);sum.append(el('span',name,'group-label'));for(const value of [m.quantity,currency(m.cost),currency(m.estimated),currency(m.average),number(m.averageDays),m.completed,m.open])sum.append(el('span',value));sum.title='Net '+currency(m.actual)+' · recovery '+number(m.recoveryPercent)+(m.recoveryPercent===null?'':'%');d.append(sum);let loaded=false;d.ontoggle=()=>{if(d.open&&!loaded){d.append(branch(group,level+1));loaded=true;}};wrap.append(d);}return wrap;}
        function render(){filtered=rows.filter(row=>{for(const [field,control] of Object.entries(controls)){const value=control.value;if(!value)continue;if(field==='search'){if(![row.serial_number,row.product_id,row.product_description].join(' ').toLowerCase().includes(value.toLowerCase()))return false;}else if(field.startsWith('entered_')||field.startsWith('outcome_')){const column=field.startsWith('entered_')?'entered_recovery_at':'outcome_at';if(!row[column])return false;const date=row[column].slice(0,10);if(field.endsWith('_from')?date<value:date>value)return false;}else if(field.startsWith('age_')){const age=days(row);if(age===null||(field==='age_min'?age<Number(value):age>Number(value)))return false;}else if(field==='defect_codes'){if(!row.defects.includes(value))return false;}else if(String(row[field])!==value)return false;}return true;});
            const visible=new Set(filtered.map(r=>String(r.id)));selected=new Set([...selected].filter(id=>visible.has(id)));const m=metrics(filtered);const cards=document.getElementById('metrics');cards.replaceChildren();for(const [label,value] of [['Devices',m.quantity],['Known cost basis',currency(m.cost)],['Estimated recovery',currency(m.estimated)],['Actual recovery',currency(m.actual)],['Average actual / device',currency(m.average)],['Recovery / matched cost',number(m.recoveryPercent)+(m.recoveryPercent===null?'':'%')],['Average days',number(m.averageDays)],['Completed',m.completed],['Open',m.open]]){const card=el('div',label,'metric');card.append(el('strong',value));cards.append(card);}document.getElementById('summaryNote').textContent=m.missingCost+' devices have unknown costs; '+m.missingAge+' have no known Recovery entry date. Recovery percentage uses only devices with both actual recovery and cost. Missing values are not treated as zero.';document.getElementById('tree').replaceChildren(filtered.length?branch(filtered,0):el('p','No devices match these filters.'));updateSelection();}
        function renderDimensions(){const list=document.getElementById('dimensions');list.replaceChildren();dimensions.forEach((field,index)=>{const chip=el('span',fields[field],'chip');for(const [text,move] of [['↑',-1],['↓',1],['×',0]]){const b=el('button',text);b.setAttribute('aria-label',text==='×'?'Remove '+fields[field]:'Move '+fields[field]+' '+(move<0?'up':'down'));b.disabled=move!==0&&(index+move<0||index+move>=dimensions.length);b.onclick=()=>{if(move===0)dimensions.splice(index,1);else [dimensions[index],dimensions[index+move]]=[dimensions[index+move],dimensions[index]];renderDimensions();render();};chip.append(b);}list.append(chip);});const add=document.getElementById('addDimension');add.replaceChildren();for(const [field,label] of Object.entries(fields))if(!dimensions.includes(field))add.add(new Option(label,field));document.getElementById('add').disabled=!add.options.length;}
        document.getElementById('add').onclick=()=>{const value=document.getElementById('addDimension').value;if(value&&!dimensions.includes(value)){dimensions.push(value);renderDimensions();render();}};
        document.getElementById('reset').onclick=()=>{Object.values(controls).forEach(c=>c.value='');render();};
        document.getElementById('selectAll').onclick=()=>{selected=new Set(filtered.filter(eligible).map(r=>String(r.id)));updateSelection();};document.getElementById('clear').onclick=()=>{selected.clear();updateSelection();};
        document.getElementById('reroute').onclick=()=>{openRecoveryRerouteSelection([...selected]);};
        const viewKey='recovery.analysis.views.v1';let views=[];try{views=JSON.parse(localStorage.getItem(viewKey)||'[]');if(!Array.isArray(views))views=[];}catch(e){}
        function applyView(view){dimensions=view.dimensions.filter(f=>Object.hasOwn(fields,f));Object.entries(controls).forEach(([f,c])=>c.value=view.filters[f]||'');renderDimensions();render();}
        function renderViews(){const container=document.getElementById('analysisViews');if(!container)return;container.replaceChildren();const defaults=el('button','Default view');defaults.onclick=()=>applyView({dimensions:['product_id','defect_codes','recovery_type'],filters:{}});container.append(defaults);views.forEach(v=>{const b=el('button',v.name);b.onclick=()=>applyView(v);container.append(b);});}
        const save=document.getElementById('saveAnalysisView');if(save)save.onclick=()=>{const name=prompt('Name this saved view:');if(!name||!name.trim())return;const view={name:name.trim().slice(0,80),dimensions:[...dimensions],filters:Object.fromEntries(Object.entries(controls).map(([f,c])=>[f,c.value]))};const updated=[...views.filter(v=>v.name!==view.name),view];try{localStorage.setItem(viewKey,JSON.stringify(updated));views=updated;renderViews();}catch(e){alert('This browser could not save the view.');}};
        renderViews();
        renderDimensions();render();
    }
    const api={metrics,grouped,mount};if(typeof module!=='undefined'&&module.exports)module.exports=api;global.RecoveryAnalysis=api;
})(typeof window==='undefined'?globalThis:window);
