import React from 'react';
import { AlertTriangle, RefreshCw } from 'lucide-react';

interface Props {
  children: React.ReactNode;
}

interface State {
  error: Error | null;
}

/**
 * Round 2 Phase 1: the shared root cause behind "the page went blank" bug
 * reports across the app -- there was no error boundary anywhere, so any
 * uncaught error while rendering (a failed save leaving state in a shape
 * a component didn't expect, a null field the code assumed was always
 * present, etc.) crashed the entire React tree to a blank white screen,
 * recoverable only by a manual refresh. This catches that class of error
 * wherever it happens and shows a real message instead.
 *
 * Placed around each page's content in Layout (not around the whole app),
 * so a crash in one page leaves the sidebar/nav intact and usable --
 * the user can navigate elsewhere without reloading.
 */
class ErrorBoundary extends React.Component<Props, State> {
  state: State = { error: null };

  static getDerivedStateFromError(error: Error): State {
    return { error };
  }

  componentDidCatch(error: Error, info: React.ErrorInfo) {
    // eslint-disable-next-line no-console
    console.error('Page crashed:', error, info.componentStack);
  }

  render() {
    if (this.state.error) {
      return (
        <div className="min-h-[50vh] flex items-center justify-center px-4">
          <div className="max-w-md text-center">
            <AlertTriangle className="w-10 h-10 text-red-500 mx-auto mb-3" />
            <h2 className="text-lg font-semibold text-gray-900 mb-1">This page hit a problem</h2>
            <p className="text-sm text-gray-600 mb-4">
              Something didn't load the way it should have. Your data is safe -- this is a display
              error, not a lost save. Try again, and if it keeps happening, tell whoever manages
              the system what you were doing when it happened.
            </p>
            <p className="text-xs text-gray-400 font-mono mb-4 break-words">{this.state.error.message}</p>
            <button
              onClick={() => { this.setState({ error: null }); window.location.reload(); }}
              className="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700"
            >
              <RefreshCw className="w-4 h-4 mr-2" />
              Reload this page
            </button>
          </div>
        </div>
      );
    }
    return this.props.children;
  }
}

export default ErrorBoundary;
