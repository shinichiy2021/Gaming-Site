import { createRoot } from 'react-dom/client';
import TeslaFlowDiagram from './TeslaFlowDiagram';

const mountNode = document.getElementById( 'tesla-energy-flow-root' );

function localizeLabels( labels ) {
	if ( ! labels || typeof labels !== 'object' ) {
		return labels;
	}

	const t = typeof window !== 'undefined' && typeof window.gamingHubT === 'function'
		? window.gamingHubT
		: null;
	if ( ! t ) {
		return labels;
	}

	const out = { ...labels };
	Object.keys( out ).forEach( ( key ) => {
		if ( typeof out[ key ] === 'string' ) {
			out[ key ] = t( out[ key ] );
		}
	} );
	return out;
}

if ( mountNode ) {
	const initial = mountNode.dataset.initial ? JSON.parse( mountNode.dataset.initial ) : {};
	const labels = localizeLabels( window.gamingHubTeslaFlow?.labels || {
		title: 'Tesla 電力フロー',
		wall: '普通充電',
		wallNote: '200V',
		homeAc: '自宅 AC',
		awayAc: '外出先 AC',
		super: '急速充電',
		superNote: '急速充電',
		tesla: 'Tesla',
		drive: 'ガソリン換算',
		rearMotor: 'モーター',
		regen: '回生充電',
		regenNote: '減速・ブレーキ',
		cabin: '車内電力',
		others: 'その他',
		flow: 'Tesla の入出力',
		idle: '待機',
		connected: '接続中',
		charging: '充電中',
		driving: '走行中',
		climate: 'エアコン',
		sentry: 'Sentry',
		asleep: 'スリープ中',
		drivePending: '走行データ未取得',
		shift: 'シフト',
		park: 'パーキング',
		reverse: 'リバース',
		neutral: 'ニュートラル',
		driveGear: 'ドライブ',
		shiftUnknown: 'シフト未取得',
		saved: '節約',
		todayUse: '今日 使用',
		todayBill: '今日 電気代',
		powerCost: '消費 / 電気代',
		todayPowerCost: '今日 / 電気代',
		buy: '買電',
		todayBuy: '今日 買電',
		yenPerHour: '円/時',
		session: '今回',
		total: '合計',
	} );

	createRoot( mountNode ).render(
		<TeslaFlowDiagram initial={ initial } labels={ labels } />
	);
}
