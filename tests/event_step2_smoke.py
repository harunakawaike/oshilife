"""Step 2の受付方式・参加確定・無料・権限・支払いをHTTPで検証する。既存データは全行比較で保護する。"""
import event_test_support as support
from event_test_support import *
from datetime import datetime,timezone,timedelta
from decimal import Decimal
import os,tempfile
now=datetime.now(timezone.utc).astimezone(timezone(timedelta(hours=9)))
today=now.date().isoformat();future=(now.date()+timedelta(days=10)).isoformat()
a,b,c=Client(),Client(),Client();before=db('snapshot')
try:
    aid,bid=a.register_login(emails[0]),b.register_login(emails[1])
    oid=post(a,'oshis/create',{'name':'Payment-'+key,'oshi_type':'actor','emoji':'🎟️'},201)['oshi']['id']
    post(b,'oshis/follow',{'oshi_id':oid})
    vid=post(a,'venues/create',{'name':'Payment-'+key,'prefecture':'東京都','address':'検証会場'},201)['id']
    events={}
    def create(kind):
        payload={'oshi_id':oid,'title':kind+' '+key,'event_type':kind,'allow_duplicate':True,'venue_id':vid,'event_date':future,'status':'scheduled','start_time':'14:00'}
        e=post(a,'events/create',payload,201)['event'];assert e['event_type']==kind;events[kind]=e
        return e,payload
    def manage(e,**patch):
        payload={'event_id':e['id'],'entry_method':'first_come','application_status':'applied','lottery_status':'not_applicable','participation_status':'confirmed','sales_type':'none','trip_type':'local','ticket_amount':'1200','note':'本人のみ',**patch}
        post(a,'events/status/update',payload)
        return get(a,'events/status',event_id=e['id'])['status']
    live,payload=create('live')
    state=manage(live,entry_method='lottery',lottery_status='pending',participation_status='considering',application_status='in_progress')
    assert state['application_status']=='applying' and state['participation_status']=='considering'
    assert get(a,'events/todos/list',event_id=live['id'])['todos']==[]
    state=manage(live,entry_method='lottery',lottery_status='won')
    assert state['lottery_status']=='won' and state['participation_status']=='confirmed'
    todos=get(a,'events/todos/list',event_id=live['id'])['todos'];assert len(todos)==6
    ids={t['id'] for t in todos};tid=todos[-1]['id']
    post(a,'events/todos/delete',{'id':tid})
    manage(live,entry_method='lottery',lottery_status='won',participation_status='cancelled')
    manage(live,entry_method='lottery',lottery_status='won')
    assert {t['id'] for t in get(a,'events/todos/list',event_id=live['id'])['todos']}==ids-{tid}
    # 新しいフォームは参加状況を明示する。落選後に別枠で参加できても編集可能。
    state=manage(live,entry_method='lottery',lottery_status='lost');assert state['participation_status']=='confirmed'
    for kind,method in [('event','first_come'),('performance','reservation'),('exhibition','no_application'),('sports','first_come'),('other','unknown')]:
        e,payload=create(kind);state=manage(e,entry_method=method,ticket_amount='0' if kind=='exhibition' else '1200',trip_type='trip' if kind in ['sports','exhibition'] else 'local')
        assert state['lottery_status']=='not_applicable' and state['participation_status']=='confirmed'
        assert len(get(a,'events/todos/list',event_id=e['id'])['todos'])>=5
        if kind=='exhibition':assert state['application_status']=='not_required'
    # 種類編集・省略時保持・入力許可値の拒否。
    post(a,'events/update',{**payload,'id':events['other']['id'],'event_type':'performance'})
    post(a,'events/update',{k:v for k,v in {**payload,'id':events['other']['id']}.items() if k!='event_type'})
    assert get(a,'events/detail',id=events['other']['id'])['event']['event_type']=='performance'
    for bad in ['bad',None,[],123]:post(a,'events/create',{**payload,'event_type':bad},422)
    e=events['event']
    for field in ['entry_method','application_status','lottery_status','participation_status','sales_type','ticket_payment_status']:
        for bad in ['bad',[],None]:post(a,'events/status/update',{'event_id':e['id'],'entry_method':'first_come',field:bad},422)
    # 他人の本人管理・TODO・遠征は共有しない。user_idを送っても本人から取得する。
    assert get(b,'events/status',event_id=e['id'])['status'] is None
    assert get(b,'events/detail',id=e['id'])['event']['personal_note'] is None
    assert get(b,'events/todos/list',event_id=e['id'])['todos']==[]
    private_todo=get(a,'events/todos/list',event_id=e['id'])['todos'][0]['id']
    post(b,'events/todos/toggle',{'id':private_todo,'is_completed':True},404)
    post(b,'events/status/update',{'event_id':e['id'],'entry_method':'reservation','participation_status':'considering','user_id':aid})
    assert get(a,'events/status',event_id=e['id'])['status']['entry_method']=='first_come'
    # 先着でも直接支払済みを選べる。TODOと同期し、source_idは本人管理のID。
    state=manage(e,ticket_payment_status='paid',sales_type='general_sale');sid=state['id']
    source=get(a,'money/source',source_type='live_ticket',source_id=sid)
    source=source['source'];assert source['can_import'] and str(source['source_id'])==str(sid)
    assert any(t['template_key']=='payment' and int(t['is_completed'])==1 for t in get(a,'events/todos/list',event_id=e['id'])['todos'])
    ticket=post(a,'money/expenses/create',source,201)['record'];assert ticket['category']=='live_ticket'
    post(a,'money/expenses/create',source,409)
    b.call('api/money/source.php?source_type=live_ticket&source_id='+str(sid),status=404)
    # 無料イベントの支払不要・反映拒否。参加確定と遠征は利用可能。
    free=events['exhibition'];free_status=get(a,'events/status',event_id=free['id'])['status']
    src=get(a,'money/source',source_type='live_ticket',source_id=free_status['id'])['source']
    assert not src['can_import'] and src['payment_status']=='not_required'
    assert not any(t['template_key']=='payment' for t in get(a,'events/todos/list',event_id=free['id'])['todos'])
    _,html=a.call('event_detail.php?id='+str(free['id']));assert 'お金管理に反映</a>' not in html
    post(a,'money/expenses/create',{**src,'amount':'1'},409)
    trips={}
    for kind in ['sports','exhibition']:
        event=events[kind]
        trip=post(a,'trips/create',{'event_id':event['id'],'trip_name':kind,'departure_date':future,'return_date':future,'note':''})['id'];trips[kind]=trip
        b.call('api/trips/detail.php?id='+str(trip),status=404)
    # 交通・ホテルの支払い反映は従来どおり。
    trip=trips['sports']
    tr=post(a,'trips/transport/create',{'trip_id':trip,'transport_type':'local_train','departure_place':'A','arrival_place':'B','departure_at':future+'T10:00','arrival_at':future+'T11:00','amount':'500','reservation_status':'reserved','payment_status':'paid','note':''})['id']
    hotel=post(a,'trips/accommodation/create',{'trip_id':trip,'hotel_name':'検証ホテル','check_in_at':future+'T15:00','check_out_at':(now.date()+timedelta(days=11)).isoformat()+'T10:00','amount':'6000','reservation_status':'reserved','payment_status':'paid','address':'','url':'','note':''})['id']
    for source_type,source_id in [('transportation',tr),('accommodation',hotel)]:
        src=get(a,'money/source',source_type=source_type,source_id=source_id)['source'];assert src['can_import'] and not src['special_effect_eligible']
        post(a,'money/expenses/create',src,201);post(a,'money/expenses/create',src,409)
    dashboard=get(a,'money/dashboard',year=now.year,oshi_id=oid)
    assert Decimal(dashboard['expenses'])==7700 and Decimal(dashboard['special_effect_eligible'])==1200
    # 共有予定のカテゴリはevent。修正提案でもLIVE固定条件に戻さない。
    schedule_id=events['performance']['schedule_id']
    assert get(b,'schedules/detail',id=schedule_id)['schedule']['category']=='event'
    proposal=post(b,'schedules/corrections/create',{'schedule_id':schedule_id,'field_name':'title','new_value':'予約舞台修正','reason':'確認'},201)
    post(a,'schedules/corrections/approve',{'correction_id':proposal['correction_id']})
    assert get(a,'events/detail',id=events['performance']['id'])['event']['title']=='予約舞台修正'
    for reaction in ['helped','thanks']:post(b,'schedules/reactions/toggle',{'schedule_id':schedule_id,'reaction_type':reaction})
    # カレンダー同期と自分用編集を分離する。
    post(b,'schedules/add-to-calendar',{'schedule_id':schedule_id})
    assert any(str(s['id'])==str(schedule_id) for s in get(b,'schedules/calendar',year=int(future[:4]),month=int(future[5:7]))['schedules'])
    # ホームは不参加・取消を除外し、検討中も日付順で残す。
    for event in events.values():post(a,'events/status/update',{'event_id':event['id'],'participation_status':'not_attending'})
    assert get(a,'events/next',oshi_id=oid)['event'] is None
    post(a,'events/status/update',{'event_id':e['id'],'participation_status':'considering'})
    assert str(get(a,'events/next',oshi_id=oid)['event']['id'])==str(e['id'])
    post(a,'events/status/update',{'event_id':e['id'],'participation_status':'cancelled'})
    assert get(a,'events/next',oshi_id=oid)['event'] is None
    for event in events.values():post(a,'events/status/update',{'event_id':event['id'],'participation_status':'confirmed'})
    # 旧販売区分は安全に移し、参加確定を推測しない。
    post(a,'lives/status/update',{'live_event_id':live['id'],'application_status':'applying','lottery_status':'production_release','trip_type':'local','note':''})
    state=get(a,'events/status',event_id=live['id'])['status'];assert state['sales_type']=='production_release' and state['participation_status']=='considering' and state['lottery_status']=='not_applicable'
    assert get(a,'lives/status',live_event_id=live['id'])['status']['lottery_status']=='production_release'
    manage(live,entry_method='lottery',lottery_status='won')
    if os.environ.get('OSHILIFE_BROWSER_TEST')=='1':
        pages=['events.php','event_form.php','home.php','money.php','trip_detail.php?id='+str(trip)]
        expectations={}
        for kind in ['live','event','performance','exhibition','sports']:
            page='event_detail.php?id='+str(events[kind]['id']);pages.append(page)
            expectations[page]={'entry':{'live':'lottery','event':'first_come','performance':'reservation','exhibition':'no_application','sports':'first_come'}[kind],'free':kind=='exhibition'}
        with tempfile.NamedTemporaryFile(mode='w',suffix='.json') as config:
            json.dump({'base':args.base,'cookies':[{'name':c.name,'value':c.value,'path':c.path} for c in a.jar],'pages':pages,'managementExpect':expectations},config);config.flush()
            subprocess.run(['node',str(root/'tests/events_browser.mjs'),config.name],check=True,timeout=120)
finally:
    after=db('cleanup');assert before==after,'既存データが変化しました'
print(f'Step 2: {support.checks} HTTP checks passed; all 21 existing table snapshots unchanged.')
