import { createRoot } from 'react-dom/client';
import TeslaFlowDiagram from './TeslaFlowDiagram';

const mountNode = document.getElementById( 'tesla-energy-flow-root' );

const JA_LABEL_FALLBACKS = {
	'Power / cost': '消費 / 電気代',
	'Today / cost': '今日 / 電気代',
	"Today's power / cost": '今日 / 電気代',
	Supercharger: '急速充電',
	'Climate control': 'エアコン',
	Motor: 'モーター',
	'Front motor': 'フロントモーター',
	Others: 'その他',
};

const DEFAULT_LABELS_JA = {
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
};

const DEFAULT_LABELS_EN = {
	title: 'Tesla energy flow',
	wall: 'AC charging',
	wallNote: '200V',
	homeAc: 'Home AC',
	awayAc: 'Away AC',
	super: 'Supercharger',
	superNote: 'Supercharger',
	tesla: 'Tesla',
	drive: 'Gasoline equivalent',
	rearMotor: 'Motor',
	regen: 'Regen charging',
	regenNote: 'Braking / regen',
	cabin: 'Cabin power',
	others: 'Others',
	flow: 'Tesla input and output',
	idle: 'Standby',
	connected: 'Connected',
	charging: 'Charging',
	driving: 'Driving',
	climate: 'Climate control',
	sentry: 'Sentry',
	asleep: 'Asleep',
	drivePending: 'Driving data not available',
	shift: 'Shift',
	park: 'Park',
	reverse: 'Reverse',
	neutral: 'Neutral',
	driveGear: 'Drive',
	shiftUnknown: 'Shift unavailable',
	saved: 'Saved',
	todayUse: 'Used today',
	todayBill: "Today's electricity cost",
	powerCost: 'Power / cost',
	todayPowerCost: 'Today / cost',
	buy: 'Grid import',
	todayBuy: 'Import today',
	yenPerHour: 'yen/h',
	session: 'Session',
	total: 'Total',
};

function isJapaneseUi() {
	if ( typeof window !== 'undefined' && typeof window.gamingHubLang === 'function' ) {
		return window.gamingHubLang() === 'ja';
	}

	return !( typeof window !== 'undefined' && window.gamingHubI18n && window.gamingHubI18n.lang === 'en' );
}

function localizeLabels( labels ) {
	if ( ! labels || typeof labels !== 'object' ) {
		return labels;
	}

	const isJa = isJapaneseUi();
	const t = typeof window !== 'undefined' && typeof window.gamingHubT === 'function'
		? window.gamingHubT
		: null;
	const out = { ...labels };
	Object.keys( out ).forEach( ( key ) => {
		if ( typeof out[ key ] !== 'string' ) {
			return;
		}
		let value = out[ key ];
		if ( isJa && t ) {
			value = t( value );
		}
		if ( isJa && JA_LABEL_FALLBACKS[ value ] ) {
			value = JA_LABEL_FALLBACKS[ value ];
		}
		out[ key ] = value;
	} );
	return out;
}

if ( mountNode ) {
	const initial = mountNode.dataset.initial ? JSON.parse( mountNode.dataset.initial ) : {};
	const defaults = isJapaneseUi() ? DEFAULT_LABELS_JA : DEFAULT_LABELS_EN;
	const labels = localizeLabels( window.gamingHubTeslaFlow?.labels || defaults );

	createRoot( mountNode ).render(
		<TeslaFlowDiagram initial={ initial } labels={ labels } />
	);
}
