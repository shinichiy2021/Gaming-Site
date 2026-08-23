import { createRoot } from 'react-dom/client';
import TeslaFlowDiagram from './TeslaFlowDiagram';

const mountNode = document.getElementById( 'tesla-energy-flow-root' );

if ( mountNode ) {
	const initial = mountNode.dataset.initial ? JSON.parse( mountNode.dataset.initial ) : {};
	const labels = window.gamingHubTeslaFlow?.labels || {
		title: 'Tesla 電力フロー',
		wall: '普通充電',
		wallNote: 'Wall Connector',
		super: '急速充電',
		superNote: 'Supercharger',
		tesla: 'Tesla',
		drive: 'ガソリン換算',
		regen: '回生充電',
		regenNote: '減速・ブレーキ',
		cabin: '車内電力',
		flow: 'Tesla の入出力',
		idle: '待機',
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
		gasToday: '本日',
		gasCar: '普通車 15 km/L',
	};

	createRoot( mountNode ).render(
		<TeslaFlowDiagram initial={ initial } labels={ labels } />
	);
}
