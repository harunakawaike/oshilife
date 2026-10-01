"""支払い連携のHTTP回帰検証。共通部品を使い既存データを維持する。"""
import event_test_support as support
from event_test_support import *
from decimal import Decimal
now=datetime.now(timezone.utc).astimezone(timezone(timedelta(hours=9)))
today=now.date().isoformat();a,b,anonymous=Client(),Client(),Client();before=db('snapshot')
def summary():return get(a,'money/dashboard',year=now.year)
def source(kind,source_id,client=a):return get(client,'money/source',source_type=kind,source_id=source_id)['source']
def event_status():return get(a,'events/status',event_id=lid)['status']
def assert_button(page,kind,item_id,present):
    _,html=a.call(page)
    link=f'source_type={kind}&amp;source_id={item_id}'
    assert (link in html)==present,(page,kind,present)
    assert 'Warning:' not in html and 'Fatal error' not in html
try:
    anonymous.call('api/money/source.php?source_type=live_ticket&source_id=1',status=401)
    aid,bid=a.register_login(emails[0]),b.register_login(emails[1])
    oid=post(a,'oshis/create',{'name':'Payment-'+key,'oshi_type':'group','emoji':'💰'},201)['oshi']['id']
    post(b,'oshis/follow',{'oshi_id':oid})
    vid=post(a,'venues/create',{'name':'Payment-'+key,'prefecture':'東京都'},201)['id']
    live=post(a,'events/create',{'oshi_id':oid,'title':'支払い連携イベント','venue_id':vid,'event_date':today,'status':'scheduled'},201)['event'];lid=live['id']
    status={'event_id':lid,'application_status':'applied','lottery_status':'won','trip_type':'local','ticket_amount':'15000','note':''}
    post(a,'events/status/update',status)
    sid=event_status()['id']
    assert event_status()['ticket_amount']=='15000.00'
    assert get(b,'events/detail',id=lid)['event']['ticket_amount'] is None
    todos=get(a,'events/todos/list',event_id=lid)['todos'];payment=next(row for row in todos if row['template_key']=='payment')['id']
    # 名前ではなく固定キーで識別するため、名前を編集しても動く。
    post(a,'events/todos/update',{'id':payment,'title':'支払い済みをチェックする','due_date':today})
    src=source('live_ticket',sid);assert src['can_import'] is False and src['expense_id'] is None
    assert_button('event_detail.php?id='+str(lid),'live_ticket',sid,False)
    a.call('expense_form.php?source_type=live_ticket&source_id='+str(sid),status=409)
    post(a,'money/expenses/create',src,409)
    assert Decimal(summary()['expenses'])==0
    post(b,'events/todos/toggle',{'id':payment,'is_completed':True},404)
    post(a,'events/todos/toggle',{'id':payment,'is_completed':True})
    assert event_status()['ticket_payment_status']=='paid' and event_status()['ticket_paid_date']==today
    src=source('live_ticket',sid);assert src['can_import'] and src['special_effect_eligible'] and src['expense_date']==today
    assert_button('event_detail.php?id='+str(lid),'live_ticket',sid,True)
    assert Decimal(summary()['expenses'])==0
    b.call('api/money/source.php?source_type=live_ticket&source_id='+str(sid),status=404)
    post(b,'money/expenses/create',src,404)
    # 確認後に状態が変わった場合も、保存APIが最新状態で拒否する。
    post(a,'events/todos/toggle',{'id':payment,'is_completed':False})
    post(a,'money/expenses/create',src,409)
    post(a,'events/todos/toggle',{'id':payment,'is_completed':True})
    for patch in [{'ticket_amount':'0'},{'lottery_status':'lost'},{'ticket_amount':''}]:
        post(a,'events/status/update',{**status,**patch});assert source('live_ticket',sid)['can_import'] is False
        post(a,'money/expenses/create',src,409)
    post(a,'events/status/update',status)
    src=source('live_ticket',sid)
    post(a,'money/expenses/create',{**src,'special_effect_eligible':False},422)
    a.call('api/money/expenses/create.php','POST',src,status=419,token=False)
    _,html=a.call('expense_form.php?source_type=live_ticket&source_id='+str(sid));assert 'checked disabled' in html
    ticket=post(a,'money/expenses/create',src,201)['record'];eid=ticket['id']
    assert int(event_status()['ticket_expense_id'])==int(eid)
    assert ticket['category']=='live_ticket' and int(ticket['special_effect_eligible'])==1
    assert Decimal(summary()['expenses'])==15000 and Decimal(summary()['special_effect_eligible'])==15000
    post(a,'money/expenses/create',src,409)
    assert_button('event_detail.php?id='+str(lid),'live_ticket',sid,False)
    post(a,'events/status/update',{**status,'ticket_amount':'16000'})
    _,html=a.call('event_detail.php?id='+str(lid));assert '反映済みの金額と現在の金額が異なります' in html
    assert source('live_ticket',sid)['amount_changed'] is True
    assert Decimal(get(a,'money/expenses/detail',id=eid)['record']['amount'])==15000
    post(a,'money/expenses/update',{**ticket,'amount':'16000'})
    assert source('live_ticket',sid)['amount_changed'] is False
    post(a,'money/expenses/delete',{'id':eid});assert event_status()['ticket_expense_id'] is None
    assert source('live_ticket',sid)['can_import']
    post(a,'money/expenses/create',source('live_ticket',sid),201)
    post(a,'events/status/update',{**status,'ticket_amount':'16000','trip_type':'trip'})
    trip=post(a,'trips/create',{'event_id':lid,'trip_name':'連携検証遠征','departure_date':today,'return_date':today,'note':''})['id']
    transport={'trip_id':trip,'transport_type':'shinkansen','departure_place':'東京','arrival_place':'大阪','departure_at':today+'T09:00','arrival_at':today+'T12:00','amount':'24000','reservation_status':'reserved','note':''}
    hotel={'trip_id':trip,'hotel_name':'ホテル','check_in_at':today+'T15:00','check_out_at':(now.date()+timedelta(days=1)).isoformat()+'T10:00','amount':'8000','reservation_status':'reserved','note':''}
    for kind,api_kind,payload in [('transportation','transport',transport),('accommodation','accommodation',hotel)]:
        item=post(a,'trips/'+api_kind+'/create',payload)['id']
        src=source(kind,item);assert src['payment_status']=='unpaid' and not src['can_import']
        assert_button('trip_detail.php?id='+str(trip),kind,item,False)
        post(a,'money/expenses/create',src,409)
        for patch in [{'payment_status':'paid','reservation_status':'considering'},{'payment_status':'paid','reservation_status':'cancelled'},{'payment_status':'paid','amount':'0'}]:
            post(a,'trips/'+api_kind+'/update',{**payload,'id':item,**patch});assert not source(kind,item)['can_import']
            post(a,'money/expenses/create',src,409)
        paid={**payload,'id':item,'payment_status':'paid'}
        before_amount=summary()['expenses']
        post(a,'trips/'+api_kind+'/update',paid);src=source(kind,item)
        assert src['can_import'] and src['expense_date']==today and src['special_effect_eligible'] is False
        assert summary()['expenses']==before_amount
        assert_button('trip_detail.php?id='+str(trip),kind,item,True)
        _,html=a.call('travel_form.php?trip_id='+str(trip)+'&kind='+api_kind+'&id='+str(item));assert 'name="payment_status"' in html
        b.call('api/money/source.php?source_type='+kind+'&source_id='+str(item),status=404)
        post(b,'money/expenses/create',src,404)
        post(a,'money/expenses/create',{**src,'special_effect_eligible':True},422)
        record=post(a,'money/expenses/create',src,201)['record'];assert record['category']==kind and int(record['special_effect_eligible'])==0
        assert int(source(kind,item)['expense_id'])==int(record['id'])
        post(a,'money/expenses/create',src,409)
        assert_button('trip_detail.php?id='+str(trip),kind,item,False)
        post(a,'trips/'+api_kind+'/update',{**paid,'amount':'25000'})
        assert source(kind,item)['amount_changed'] is True
        assert get(a,'money/expenses/detail',id=record['id'])['record']['amount']==record['amount']
        post(a,'money/expenses/delete',{'id':record['id']});assert source(kind,item)['expense_id'] is None and source(kind,item)['can_import']
        post(a,'money/expenses/create',source(kind,item),201)
        # 支払済みのまま別項目だけ更新しても、支払日を失わない。
        post(a,'trips/'+api_kind+'/update',{**payload,'id':item,'amount':'25000'})
        assert source(kind,item)['payment_status']=='paid'
    assert Decimal(summary()['expenses'])==66000 and Decimal(summary()['special_effect_eligible'])==16000
    assert sum(Decimal(row['amount']) for row in summary()['monthly'])==66000
    assert sum(Decimal(row['amount']) for row in summary()['categories'])==66000
    # payment以外のTODOはチケットの支払い状態を変えない。
    other=next(row['id'] for row in get(a,'events/todos/list',event_id=lid)['todos'] if row['template_key']=='hotel')
    post(a,'events/todos/toggle',{'id':other,'is_completed':True});assert event_status()['ticket_payment_status']=='paid'
    post(a,'events/todos/delete',{'id':payment});assert event_status()['ticket_payment_status']=='unpaid'
    last=event_status()['ticket_expense_id'];post(a,'money/expenses/delete',{'id':last})
    assert source('live_ticket',sid)['can_import'] is False
finally:
    after=db('cleanup');assert before==after,'既存データが変化しました'
print(f'Payment import: {support.checks} HTTP checks passed; all 21 existing table snapshots unchanged.')
